<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * MailRunner Admin (decision E-19): own database. Customers and projects are kept here (desired state)
 * and pushed to the nodes through their admin API; node status is polled.
 */
class AdminCore extends Migration
{
    public function up(): void
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $this->db->query(<<<SQL
            CREATE TABLE users (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entra_oid     CHAR(36)     NOT NULL,
                tenant_id     CHAR(36)     NOT NULL,
                name          VARCHAR(255) NOT NULL,
                email         VARCHAR(320) NULL,
                roles         JSON         NULL,
                created_at    DATETIME(6)  NOT NULL,
                last_login_at DATETIME(6)  NULL,
                UNIQUE KEY uq_users_oid (tenant_id, entra_oid)
            ) {$opts}
            SQL);
        $this->db->query(<<<SQL
            CREATE TABLE nodes (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                node_key        VARCHAR(64)  NOT NULL COMMENT 'node id, e.g. node1.eu',
                name            VARCHAR(255) NOT NULL,
                base_url        VARCHAR(255) NOT NULL COMMENT 'https://<node host>',
                enabled         TINYINT(1)   NOT NULL DEFAULT 1,
                last_checked_at DATETIME(6)  NULL,
                last_ok_at      DATETIME(6)  NULL,
                last_status     JSON         NULL COMMENT 'GET /admin/v1/node',
                last_error      VARCHAR(500) NULL,
                created_at      DATETIME(6)  NOT NULL,
                updated_at      DATETIME(6)  NOT NULL,
                UNIQUE KEY uq_nodes_key (node_key)
            ) {$opts}
            SQL);
        $this->db->query(<<<SQL
            CREATE TABLE customers (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_key VARCHAR(40)  NOT NULL,
                name         VARCHAR(255) NOT NULL,
                enabled      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at   DATETIME(6)  NOT NULL,
                updated_at   DATETIME(6)  NOT NULL,
                UNIQUE KEY uq_customers_key (customer_key)
            ) {$opts}
            SQL);
        $this->db->query(<<<SQL
            CREATE TABLE projects (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_id  BIGINT UNSIGNED NOT NULL,
                node_id      BIGINT UNSIGNED NOT NULL,
                project_key  VARCHAR(40)  NOT NULL,
                name         VARCHAR(255) NOT NULL,
                profile      ENUM('analysis','checked','checked_auth') NOT NULL,
                enabled      TINYINT(1)   NOT NULL DEFAULT 1,
                routes       JSON         NOT NULL,
                settings     JSON         NOT NULL,
                changed_at   DATETIME(6)  NOT NULL COMMENT 'last change here',
                synced_at    DATETIME(6)  NULL COMMENT 'last successful push to the node',
                node_revision INT UNSIGNED NULL,
                sync_error   VARCHAR(500) NULL,
                created_at   DATETIME(6)  NOT NULL,
                UNIQUE KEY uq_projects_key (customer_id, project_key),
                KEY idx_projects_node (node_id),
                CONSTRAINT fk_projects_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
                CONSTRAINT fk_projects_node FOREIGN KEY (node_id) REFERENCES nodes (id)
            ) {$opts}
            SQL);
        $this->db->query(<<<SQL
            CREATE TABLE audit_log (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME(6)  NOT NULL,
                user_id    BIGINT UNSIGNED NULL,
                action     VARCHAR(64)  NOT NULL,
                object     VARCHAR(255) NULL,
                details    JSON         NULL,
                KEY idx_audit_created (created_at)
            ) {$opts}
            SQL);
    }

    public function down(): void
    {
        foreach (['audit_log', 'projects', 'customers', 'nodes', 'users'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $t);
        }
    }
}
