<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Journal: one gap-free, cursor-safe event list per node.
 * IDs come from journal_sequence (row lock), not AUTO_INCREMENT.
 */
class CoreJournal extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE journal_sequence (
                id      TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                next_id BIGINT UNSIGNED  NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
        $this->db->query('INSERT INTO journal_sequence (id, next_id) VALUES (1, 1)');

        $this->db->query(<<<'SQL'
            CREATE TABLE journal (
                id          BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                created_at  DATETIME(6)     NOT NULL,
                event       VARCHAR(64)     NOT NULL,
                source      ENUM('cli','admin','api','system') NOT NULL,
                actor       VARCHAR(128)    NULL COMMENT 'OS user, certificate subject or token id',
                customer_id BIGINT UNSIGNED NULL,
                project_id  BIGINT UNSIGNED NULL,
                details     JSON            NULL,
                KEY idx_journal_project (project_id, id),
                KEY idx_journal_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS journal');
        $this->db->query('DROP TABLE IF EXISTS journal_sequence');
    }
}
