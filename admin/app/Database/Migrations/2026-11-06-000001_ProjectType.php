<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Project type (decision E-27): receive or send, fixed at creation. Send projects have profile smarthost, no routes,
 * and their settings are the send limits, allowed sender domains and the domain test criteria.
 */
class ProjectType extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE projects
                ADD COLUMN type ENUM('receive','send') NOT NULL DEFAULT 'receive' AFTER project_key,
                MODIFY COLUMN profile ENUM('analysis','checked','checked_auth','smarthost') NOT NULL
            SQL);
    }

    public function down(): void
    {
        $this->db->query("UPDATE projects SET profile = 'checked', enabled = 0 WHERE type = 'send'");
        $this->db->query("ALTER TABLE projects MODIFY COLUMN profile ENUM('analysis','checked','checked_auth') NOT NULL, DROP COLUMN type");
    }
}
