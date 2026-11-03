<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Common secrets for all nodes (admin settings). Values encrypted with /etc/mailrunner-admin/secrets.key;
 * settings holds the revision (random xxxx-xxxx, changes with every save) the nodes report back.
 */
class CommonSecrets extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE TABLE common_secrets (
            name        VARCHAR(64)  NOT NULL PRIMARY KEY,
            value_enc   VARBINARY(1024) NOT NULL,
            updated_at  DATETIME(6)  NOT NULL,
            updated_by  INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->db->query('CREATE TABLE settings (
            name        VARCHAR(64)  NOT NULL PRIMARY KEY,
            value       TEXT         NOT NULL,
            updated_at  DATETIME(6)  NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE common_secrets');
        $this->db->query('DROP TABLE settings');
    }
}
