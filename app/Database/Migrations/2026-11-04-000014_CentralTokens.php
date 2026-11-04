<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API tokens managed centrally by MailRunner Admin: once the admin has sent the token set of a project
 * (PUT /admin/v1/projects/... with "tokens"), the project's tokens come only from there.
 */
class CentralTokens extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE projects ADD COLUMN tokens_central TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE projects DROP COLUMN tokens_central');
    }
}
