<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Send role step 6 (E-27): bounces. Return-Path <mail-id>-<node-id>-<check>@<return_path_domain of the profile>;
 * mr-sender collects the bounces from the receive project of the bounce domain and marks the messages bounced.
 */
class SendBounces extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE send_submissions ADD COLUMN return_path_domain VARCHAR(253) NULL COMMENT 'bounce domain of the profile at submission time; NULL = envelope sender of the submission' AFTER envelope_from");
        $this->db->query("ALTER TABLE send_domains ADD COLUMN test_return_path_domain VARCHAR(253) NULL COMMENT 'return-path domain the last test was made with (NULL = the domain itself)' AFTER test_key_sha256");
        $this->db->query("ALTER TABLE send_attempts MODIFY COLUMN stage ENUM('dns','connect','greeting','ehlo','starttls','mail','rcpt','data','local','bounce') NOT NULL");
        $this->db->query(<<<'SQL'
            CREATE TABLE send_bounce_state (
                source      VARCHAR(255)    NOT NULL PRIMARY KEY COMMENT 'receive API the bounces come from',
                after_id    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'cursor of GET /v1/messages',
                checked_at  DATETIME(6)     NULL,
                last_error  VARCHAR(255)    NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS send_bounce_state');
        $this->db->query("UPDATE send_attempts SET stage = 'local' WHERE stage = 'bounce'");
        $this->db->query("ALTER TABLE send_attempts MODIFY COLUMN stage ENUM('dns','connect','greeting','ehlo','starttls','mail','rcpt','data','local') NOT NULL");
        $this->db->query('ALTER TABLE send_domains DROP COLUMN test_return_path_domain');
        $this->db->query('ALTER TABLE send_submissions DROP COLUMN return_path_domain');
    }
}
