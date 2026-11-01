<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Receive routes: a domain (catch-all) or a single address, owned by exactly one project.
 */
class ReceiveRoutes extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE receive_routes (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                route_type ENUM('domain','address') NOT NULL,
                route      VARCHAR(255)    NOT NULL COMMENT 'lower case, IDN as punycode',
                created_at DATETIME(6)     NOT NULL,
                UNIQUE KEY uq_receive_routes_route (route),
                KEY idx_receive_routes_project (project_id),
                CONSTRAINT fk_receive_routes_project FOREIGN KEY (project_id) REFERENCES projects (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS receive_routes');
    }
}
