<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Project secrets (e.g. API keys used by the analysis), encrypted with the node key.
 * Values are never shown again after they are set.
 */
class CoreProjectSecrets extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE project_secrets (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id     BIGINT UNSIGNED NOT NULL,
                name           VARCHAR(64)     NOT NULL,
                value_enc      BLOB            NOT NULL COMMENT 'NodeCrypto, context db:project_secrets:<project_id>:<name>',
                updated_at     DATETIME(6)     NOT NULL,
                updated_source ENUM('cli','admin','api','system') NOT NULL,
                UNIQUE KEY uq_project_secrets_name (project_id, name),
                CONSTRAINT fk_project_secrets_project FOREIGN KEY (project_id) REFERENCES projects (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS project_secrets');
    }
}
