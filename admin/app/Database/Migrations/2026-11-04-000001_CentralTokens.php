<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API tokens managed here (source of truth), pushed with the project to every node of the project (hash only;
 * the secret is shown once at creation and never stored). tokens_imported_at: the tokens that already existed
 * on the project's node were taken over (once), from then on the admin sends the full token set.
 */
class CentralTokens extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE api_tokens (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id   BIGINT UNSIGNED NOT NULL,
                token_id     VARCHAR(32)  NOT NULL COMMENT 'public id mrt_..., also the first part of the bearer',
                label        VARCHAR(100) NOT NULL,
                secret_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the secret',
                created_at   DATETIME(6)  NOT NULL,
                created_by   BIGINT UNSIGNED NULL,
                origin       ENUM('admin','imported') NOT NULL,
                expires_at   DATETIME(6)  NULL,
                revoked_at   DATETIME(6)  NULL,
                UNIQUE KEY uq_api_tokens_token_id (token_id),
                KEY idx_api_tokens_project (project_id),
                CONSTRAINT fk_api_tokens_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
        $this->db->query('ALTER TABLE projects ADD COLUMN tokens_imported_at DATETIME(6) NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE api_tokens');
        $this->db->query('ALTER TABLE projects DROP COLUMN tokens_imported_at');
    }
}
