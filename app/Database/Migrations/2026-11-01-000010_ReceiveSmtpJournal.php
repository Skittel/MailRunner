<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Journal of delivery attempts (smtp.connection / smtp.attempt):
 * - projects.journal_scope: which entries without a project a token may see
 * - receive_smtp_sessions: imported session ids (mr-smtplog may hand a session over twice after a restart)
 */
class ReceiveSmtpJournal extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE projects
                ADD COLUMN journal_scope ENUM('own','connections','unassigned') NOT NULL DEFAULT 'own' AFTER metadata_days
            SQL);
        $this->db->query('ALTER TABLE journal ADD KEY idx_journal_event (event, project_id, id)');
        $this->db->query(<<<'SQL'
            CREATE TABLE receive_smtp_sessions (
                session_id  VARCHAR(32) NOT NULL PRIMARY KEY,
                started_at  DATETIME(6) NOT NULL,
                imported_at DATETIME(6) NOT NULL,
                KEY idx_receive_smtp_sessions_imported (imported_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS receive_smtp_sessions');
        $this->db->query('ALTER TABLE journal DROP KEY idx_journal_event');
        $this->db->query('ALTER TABLE projects DROP COLUMN journal_scope');
    }
}
