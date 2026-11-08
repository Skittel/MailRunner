<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Send role step 2 (E-27): send profiles and their accesses (api token, SMTP login, client IP).
 *
 * API tokens and SMTP credentials of a send access live in the existing tables with scope "send": usernames stay
 * unique on the node across both roles, hashing and rotation are shared. Send tokens are never part of the central
 * token set (E-23), send credentials never reach the receive mail path.
 */
class SendProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE api_tokens ADD COLUMN scope ENUM('manage','send') NOT NULL DEFAULT 'manage' COMMENT 'manage: project API; send: submission of one send profile' AFTER project_id");
        $this->db->query("ALTER TABLE smtp_credentials ADD COLUMN scope ENUM('receive','send') NOT NULL DEFAULT 'receive' COMMENT 'receive: E-26 login; send: access of a send profile' AFTER project_id");

        $this->db->query(<<<'SQL'
            CREATE TABLE send_profiles (
                id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                profile_id            VARCHAR(32)      NOT NULL COMMENT 'public id, e.g. mrp_...',
                project_id            BIGINT UNSIGNED  NOT NULL,
                name                  VARCHAR(63)      NOT NULL COMMENT 'chosen by the application, unique per project',
                label                 VARCHAR(100)     NOT NULL,
                enabled               TINYINT(1)       NOT NULL DEFAULT 1,
                senders               JSON             NOT NULL COMMENT 'allowed sender domains and addresses',
                recipients            JSON             NULL COMMENT 'allowed recipient domains and addresses, NULL = all',
                limit_hour            INT UNSIGNED     NOT NULL,
                limit_day             INT UNSIGNED     NOT NULL,
                retry_attempts        TINYINT UNSIGNED NOT NULL,
                retry_seconds         INT UNSIGNED     NOT NULL COMMENT 'give up after this many seconds',
                return_path_domain    VARCHAR(253)     NULL COMMENT 'bounce domain (step 6)',
                test_interval_hours   SMALLINT UNSIGNED NOT NULL,
                created_at            DATETIME(6)      NOT NULL,
                updated_at            DATETIME(6)      NOT NULL,
                updated_by            VARCHAR(100)     NULL,
                UNIQUE KEY uq_send_profiles_id (profile_id),
                UNIQUE KEY uq_send_profiles_name (project_id, name),
                CONSTRAINT fk_send_profiles_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE send_accesses (
                id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                access_id             VARCHAR(32)      NOT NULL COMMENT 'public id, e.g. mra_...',
                project_id            BIGINT UNSIGNED  NOT NULL,
                profile_id            BIGINT UNSIGNED  NOT NULL,
                type                  ENUM('api','smtp','ip') NOT NULL,
                label                 VARCHAR(100)     NOT NULL,
                enabled               TINYINT(1)       NOT NULL DEFAULT 1,
                api_token_id          BIGINT UNSIGNED  NULL COMMENT 'type api: current token',
                api_token_prev_id     BIGINT UNSIGNED  NULL COMMENT 'type api: previous token during a rotation grace period',
                smtp_credential_id    BIGINT UNSIGNED  NULL COMMENT 'type smtp',
                networks              JSON             NULL COMMENT 'type ip: client networks (IPv4 /29, IPv6 /64 at most)',
                created_at            DATETIME(6)      NOT NULL,
                updated_at            DATETIME(6)      NOT NULL,
                UNIQUE KEY uq_send_accesses_id (access_id),
                KEY idx_send_accesses_profile (profile_id),
                KEY idx_send_accesses_token (api_token_id),
                KEY idx_send_accesses_token_prev (api_token_prev_id),
                CONSTRAINT fk_send_accesses_profile FOREIGN KEY (profile_id) REFERENCES send_profiles (id) ON DELETE CASCADE,
                CONSTRAINT fk_send_accesses_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_send_accesses_token FOREIGN KEY (api_token_id) REFERENCES api_tokens (id) ON DELETE CASCADE,
                CONSTRAINT fk_send_accesses_token_prev FOREIGN KEY (api_token_prev_id) REFERENCES api_tokens (id) ON DELETE SET NULL,
                CONSTRAINT fk_send_accesses_credential FOREIGN KEY (smtp_credential_id) REFERENCES smtp_credentials (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS send_accesses');
        $this->db->query('DROP TABLE IF EXISTS send_profiles');
        $this->db->query("UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)) WHERE scope = 'send'");
        $this->db->query("UPDATE smtp_credentials SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)), enabled = 0 WHERE scope = 'send'");
        $this->db->query('ALTER TABLE api_tokens DROP COLUMN scope');
        $this->db->query('ALTER TABLE smtp_credentials DROP COLUMN scope');
    }
}
