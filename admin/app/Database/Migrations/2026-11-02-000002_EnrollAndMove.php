<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Node enrollment tokens (one-time, short-lived; install.sh --enroll-token) and project moves between nodes.
 */
class EnrollAndMove extends Migration
{
    public function up(): void
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $this->db->query(<<<SQL
            CREATE TABLE node_enrollments (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token_hash  CHAR(64)     NOT NULL COMMENT 'sha256 of the token; the token itself is shown once',
                node_key    VARCHAR(64)  NOT NULL,
                hostname    VARCHAR(255) NOT NULL,
                created_by  BIGINT UNSIGNED NULL,
                created_at  DATETIME(6)  NOT NULL,
                expires_at  DATETIME(6)  NOT NULL,
                used_at     DATETIME(6)  NULL,
                node_id     BIGINT UNSIGNED NULL,
                UNIQUE KEY uq_enroll_token (token_hash)
            ) {$opts}
            SQL);
        $this->db->query('ALTER TABLE projects ADD COLUMN move_from_node_id BIGINT UNSIGNED NULL AFTER node_id');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS node_enrollments');
        $this->db->query('ALTER TABLE projects DROP COLUMN move_from_node_id');
    }
}
