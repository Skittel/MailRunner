<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Send role step 5 (E-27): domain test. mr-sender runs it (DNS pre-check, one test mail per address family to
 * SmartMailFlow, result from the SmartMailFlow API) and keeps the state in send_domains; every run is kept in
 * send_domain_tests.
 */
class SendDomainTests extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE send_domains
                ADD COLUMN test_details JSON NULL COMMENT 'result of the last completed test (per check and family)',
                ADD COLUMN test_requested_at DATETIME(6) NULL COMMENT 'test wanted: key changed, POST .../test',
                ADD COLUMN test_next_at DATETIME(6) NULL COMMENT 'next interval test',
                ADD COLUMN test_running_until DATETIME(6) NULL COMMENT 'lease of the worker running the test',
                ADD COLUMN test_error VARCHAR(255) NULL COMMENT 'last run could not complete (e.g. SmartMailFlow unavailable)'
            SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE send_domain_tests (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                domain_row_id   BIGINT UNSIGNED NOT NULL,
                project_id      BIGINT UNSIGNED NOT NULL,
                trigger_reason  ENUM('waiting','requested','interval') NOT NULL,
                key_sha256      CHAR(64)        NOT NULL,
                started_at      DATETIME(6)     NOT NULL,
                finished_at     DATETIME(6)     NOT NULL,
                outcome         ENUM('passed','failed','unavailable') NOT NULL,
                details         JSON            NULL,
                KEY idx_send_domain_tests_domain (domain_row_id, id),
                CONSTRAINT fk_send_domain_tests_domain FOREIGN KEY (domain_row_id) REFERENCES send_domains (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS send_domain_tests');
        $this->db->query('ALTER TABLE send_domains DROP COLUMN test_details, DROP COLUMN test_requested_at, DROP COLUMN test_next_at, DROP COLUMN test_running_until, DROP COLUMN test_error');
    }
}
