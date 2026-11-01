<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Access per project: several API tokens and several SMTP credentials.
 * Each has a public id (mrt_... / mrc_...) and a secret that is stored only as hash.
 */
class CoreAccess extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE api_tokens (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token_id        VARCHAR(32)     NOT NULL COMMENT 'public id, e.g. mrt_k3f9x2...',
                project_id      BIGINT UNSIGNED NOT NULL,
                label           VARCHAR(100)    NOT NULL,
                secret_hash     CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the 256-bit secret',
                created_at      DATETIME(6)     NOT NULL,
                created_source  ENUM('cli','admin','api','system') NOT NULL,
                expires_at      DATETIME(6)     NULL,
                revoked_at      DATETIME(6)     NULL,
                last_used_at    DATETIME(6)     NULL,
                rotated_from_id BIGINT UNSIGNED NULL COMMENT 'token this one replaced',
                UNIQUE KEY uq_api_tokens_token_id (token_id),
                KEY idx_api_tokens_project (project_id),
                CONSTRAINT fk_api_tokens_project FOREIGN KEY (project_id) REFERENCES projects (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE smtp_credentials (
                id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                credential_id            VARCHAR(32)     NOT NULL COMMENT 'public id, e.g. mrc_7hq2m0...',
                project_id               BIGINT UNSIGNED NOT NULL,
                username                 VARCHAR(100)    NOT NULL,
                label                    VARCHAR(100)    NOT NULL,
                password_hash            VARCHAR(255)    NOT NULL COMMENT 'Argon2id (PHP/Dovecot compatible)',
                previous_password_hash   VARCHAR(255)    NULL COMMENT 'still valid until previous_valid_until (rotation grace)',
                previous_valid_until     DATETIME(6)     NULL,
                tls_required             TINYINT(1)      NOT NULL DEFAULT 1,
                allowed_networks         JSON            NULL COMMENT 'CIDR list; NULL = any',
                enabled                  TINYINT(1)      NOT NULL DEFAULT 1,
                password_change_required TINYINT(1)      NOT NULL DEFAULT 0,
                created_at               DATETIME(6)     NOT NULL,
                updated_at               DATETIME(6)     NOT NULL,
                revoked_at               DATETIME(6)     NULL,
                last_used_at             DATETIME(6)     NULL,
                UNIQUE KEY uq_smtp_credentials_id (credential_id),
                UNIQUE KEY uq_smtp_credentials_username (username),
                KEY idx_smtp_credentials_project (project_id),
                CONSTRAINT fk_smtp_credentials_project FOREIGN KEY (project_id) REFERENCES projects (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS smtp_credentials');
        $this->db->query('DROP TABLE IF EXISTS api_tokens');
    }
}
