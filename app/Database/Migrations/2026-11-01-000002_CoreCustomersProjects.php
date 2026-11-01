<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customers and their projects. A project is the unit of configuration:
 * profile, routes, tokens, SMTP credentials, retention.
 */
class CoreCustomersProjects extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE customers (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_key VARCHAR(40)     NOT NULL,
                name         VARCHAR(150)    NOT NULL,
                enabled      TINYINT(1)      NOT NULL DEFAULT 1,
                managed_by   ENUM('cli','admin') NOT NULL DEFAULT 'cli',
                created_at   DATETIME(6)     NOT NULL,
                updated_at   DATETIME(6)     NOT NULL,
                UNIQUE KEY uq_customers_key (customer_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE projects (
                id                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_id            BIGINT UNSIGNED  NOT NULL,
                project_key            VARCHAR(40)      NOT NULL,
                name                   VARCHAR(150)     NOT NULL,
                profile                ENUM('analysis','checked','checked_auth') NOT NULL,
                enabled                TINYINT(1)       NOT NULL DEFAULT 1,
                managed_by             ENUM('cli','admin') NOT NULL DEFAULT 'cli',
                revision               INT UNSIGNED     NOT NULL DEFAULT 1,
                recipient_pattern      VARCHAR(255)     NULL COMMENT 'Regex for the local part, checked at RCPT TO',
                recipient_always_allow JSON             NULL COMMENT 'Local parts always accepted, e.g. ["postmaster","abuse"]',
                delete_after_ack_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
                unacked_days           SMALLINT UNSIGNED NOT NULL DEFAULT 14,
                metadata_days          SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                created_at             DATETIME(6)      NOT NULL,
                updated_at             DATETIME(6)      NOT NULL,
                UNIQUE KEY uq_projects_key (customer_id, project_key),
                CONSTRAINT fk_projects_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS projects');
        $this->db->query('DROP TABLE IF EXISTS customers');
    }
}
