<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Send role step 4 (E-27): delivery by the worker (mr-sender).
 *   send_attempts   every delivery attempt per message and per MX address tried
 *   send_sequence   one counter for change_seq: taken under a row lock inside the writing transaction, so the
 *                   status list (GET /v1/send/messages?after=) never skips a change committed later
 *   send_messages   change_seq, lease (claimed by a worker), last SMTP answer, sent_at
 *   send_domains    domain test state (filled by the domain test, step 5); a domain only sends with test_status
 *                   passed for exactly its current key (test_key_sha256 = key_sha256)
 */
class SendDelivery extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE TABLE send_sequence (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, seq BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->db->query('INSERT INTO send_sequence (id, seq) VALUES (1, 0)');

        $this->db->query(<<<'SQL'
            ALTER TABLE send_messages
                ADD COLUMN change_seq BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'from send_sequence on every status change' AFTER status_reason,
                ADD COLUMN lease_until DATETIME(6) NULL COMMENT 'status sending: claimed by a worker until' AFTER next_attempt_at,
                ADD COLUMN last_code SMALLINT UNSIGNED NULL AFTER lease_until,
                ADD COLUMN last_enhanced VARCHAR(16) NULL AFTER last_code,
                ADD COLUMN last_response VARCHAR(512) NULL AFTER last_enhanced,
                ADD COLUMN sent_at DATETIME(6) NULL AFTER last_response,
                ADD KEY idx_send_messages_changes (project_id, change_seq)
            SQL);
        $this->db->query('UPDATE send_messages SET change_seq = id');
        $this->db->query('UPDATE send_sequence SET seq = (SELECT COALESCE(MAX(id), 0) FROM send_messages) WHERE id = 1');

        $this->db->query(<<<'SQL'
            CREATE TABLE send_attempts (
                id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message_row_id   BIGINT UNSIGNED  NOT NULL,
                attempt_no       SMALLINT UNSIGNED NOT NULL COMMENT 'delivery attempt of the message (1 = first)',
                started_at       DATETIME(6)      NOT NULL,
                duration_ms      INT UNSIGNED     NOT NULL,
                local_ip         VARCHAR(45)      NULL,
                mx_host          VARCHAR(253)     NULL,
                mx_ip            VARCHAR(45)      NULL,
                stage            ENUM('dns','connect','greeting','ehlo','starttls','mail','rcpt','data','local') NOT NULL,
                result           ENUM('sent','deferred','bounced','failed') NOT NULL,
                smtp_code        SMALLINT UNSIGNED NULL,
                enhanced_code    VARCHAR(16)      NULL,
                response         VARCHAR(512)     NULL,
                tls_version      VARCHAR(16)      NULL,
                tls_cipher       VARCHAR(64)      NULL,
                KEY idx_send_attempts_message (message_row_id),
                CONSTRAINT fk_send_attempts_message FOREIGN KEY (message_row_id) REFERENCES send_messages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);

        $this->db->query(<<<'SQL'
            ALTER TABLE send_domains
                ADD COLUMN test_status ENUM('untested','passed','failed') NOT NULL DEFAULT 'untested' COMMENT 'domain test (step 5)',
                ADD COLUMN test_key_sha256 CHAR(64) NULL COMMENT 'key the last passed test was made with',
                ADD COLUMN test_passed_at DATETIME(6) NULL,
                ADD COLUMN test_checked_at DATETIME(6) NULL
            SQL);
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE send_domains DROP COLUMN test_status, DROP COLUMN test_key_sha256, DROP COLUMN test_passed_at, DROP COLUMN test_checked_at');
        $this->db->query('DROP TABLE IF EXISTS send_attempts');
        $this->db->query('ALTER TABLE send_messages DROP KEY idx_send_messages_changes, DROP COLUMN change_seq, DROP COLUMN lease_until, DROP COLUMN last_code,
            DROP COLUMN last_enhanced, DROP COLUMN last_response, DROP COLUMN sent_at');
        $this->db->query('DROP TABLE IF EXISTS send_sequence');
    }
}
