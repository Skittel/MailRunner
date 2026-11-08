<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Send role step 3 (E-27): submissions and one message per recipient. The mail itself is stored once per
 * submission, encrypted with the node key (send/store/YYYY/MM/DD/<submission_id>.eml.enc); the database holds
 * metadata and the queue state.
 */
class SendSubmissions extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE send_submissions (
                id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                submission_id     VARCHAR(32)      NOT NULL COMMENT 'public id, e.g. mrs_...',
                project_id        BIGINT UNSIGNED  NOT NULL,
                profile_id        BIGINT UNSIGNED  NULL COMMENT 'NULL once the profile is deleted (name stays)',
                profile_name      VARCHAR(63)      NOT NULL,
                access_id         VARCHAR(32)      NOT NULL COMMENT 'send_accesses.access_id at submission time',
                via               ENUM('api','smtp') NOT NULL,
                smtp_service      VARCHAR(16)      NULL COMMENT 'smtp (25) | submission (587) | smtps (465)',
                client_ip         VARCHAR(45)      NULL,
                envelope_from     VARCHAR(320)     NOT NULL,
                header_from       VARCHAR(320)     NOT NULL,
                from_domain       VARCHAR(253)     NOT NULL,
                subject           VARCHAR(998)     NULL,
                message_id_header VARCHAR(998)     NULL,
                recipients        INT UNSIGNED     NOT NULL,
                size              INT UNSIGNED     NOT NULL,
                sha256            CHAR(64)         NOT NULL,
                raw_path          VARCHAR(255)     NULL COMMENT 'relative to send/store; NULL once deleted',
                created_at        DATETIME(6)      NOT NULL,
                UNIQUE KEY uq_send_submissions_id (submission_id),
                KEY idx_send_submissions_project (project_id, created_at),
                CONSTRAINT fk_send_submissions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_send_submissions_profile FOREIGN KEY (profile_id) REFERENCES send_profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE send_messages (
                id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message_id        VARCHAR(32)      NOT NULL COMMENT 'public id, e.g. mrm_...',
                submission_row_id BIGINT UNSIGNED  NOT NULL,
                project_id        BIGINT UNSIGNED  NOT NULL,
                profile_id        BIGINT UNSIGNED  NULL,
                recipient         VARCHAR(320)     NOT NULL,
                recipient_domain  VARCHAR(253)     NOT NULL,
                status            ENUM('queued','waiting_test','sending','deferred','sent','bounced','failed') NOT NULL DEFAULT 'queued',
                status_reason     VARCHAR(64)      NULL,
                attempts          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                retry_attempts    TINYINT UNSIGNED NOT NULL COMMENT 'from the profile at submission time',
                next_attempt_at   DATETIME(6)      NULL,
                expires_at        DATETIME(6)      NOT NULL COMMENT 'give up (hardfail) after this time',
                created_at        DATETIME(6)      NOT NULL,
                updated_at        DATETIME(6)      NOT NULL,
                UNIQUE KEY uq_send_messages_id (message_id),
                KEY idx_send_messages_submission (submission_row_id),
                KEY idx_send_messages_project (project_id, created_at),
                KEY idx_send_messages_profile (profile_id, created_at),
                KEY idx_send_messages_queue (status, next_attempt_at),
                CONSTRAINT fk_send_messages_submission FOREIGN KEY (submission_row_id) REFERENCES send_submissions (id) ON DELETE CASCADE,
                CONSTRAINT fk_send_messages_profile FOREIGN KEY (profile_id) REFERENCES send_profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS send_messages');
        $this->db->query('DROP TABLE IF EXISTS send_submissions');
    }
}
