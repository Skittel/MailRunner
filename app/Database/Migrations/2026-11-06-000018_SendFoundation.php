<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Send role, step 1 (decision E-27): project type (receive | send, fixed at creation), send limits, allowed sender
 * domains and domain test criteria per project (set by MailRunner Admin), sender domains with their DKIM key.
 */
class SendFoundation extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE projects
                ADD COLUMN type ENUM('receive','send') NOT NULL DEFAULT 'receive' AFTER project_key,
                MODIFY COLUMN profile ENUM('analysis','checked','checked_auth','smarthost') NOT NULL,
                ADD COLUMN send_limit_hour INT UNSIGNED NULL COMMENT 'send: mails per hour for the whole project (admin)',
                ADD COLUMN send_limit_day INT UNSIGNED NULL COMMENT 'send: mails per day for the whole project (admin)',
                ADD COLUMN send_allowed_domains JSON NULL COMMENT 'send: sender domains the project may use (admin), NULL = all',
                ADD COLUMN send_test_criteria JSON NULL COMMENT 'send: what a domain test must pass (admin checkboxes)'
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE send_domains (
                id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id        BIGINT UNSIGNED  NOT NULL,
                domain            VARCHAR(253)     NOT NULL,
                dkim_selector     VARCHAR(63)      NOT NULL,
                dkim_algorithm    ENUM('rsa','ed25519') NOT NULL,
                dkim_bits         SMALLINT UNSIGNED NOT NULL,
                dkim_private_enc  BLOB             NOT NULL COMMENT 'private key, encrypted with the node key (context dkim:<project_id>:<domain>)',
                dkim_public       TEXT             NOT NULL COMMENT 'public key as published in DNS (p=)',
                key_sha256        CHAR(64)         NOT NULL COMMENT 'SHA-256 of the public key: detects a changed key without decrypting',
                created_at        DATETIME(6)      NOT NULL,
                updated_at        DATETIME(6)      NOT NULL,
                updated_by        VARCHAR(100)     NULL,
                UNIQUE KEY uq_send_domains (project_id, domain),
                CONSTRAINT fk_send_domains_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS send_domains');
        // send projects cannot be represented any more: kept disabled as receive projects without routes
        $this->db->query("UPDATE projects SET profile = 'checked', enabled = 0 WHERE type = 'send'");
        $this->db->query(<<<'SQL'
            ALTER TABLE projects
                DROP COLUMN send_test_criteria, DROP COLUMN send_allowed_domains, DROP COLUMN send_limit_day,
                DROP COLUMN send_limit_hour, MODIFY COLUMN profile ENUM('analysis','checked','checked_auth') NOT NULL, DROP COLUMN type
            SQL);
    }
}
