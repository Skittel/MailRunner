<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Project secrets were replaced by node-wide credentials in /etc/mailrunner/secrets.env
 * (decision 01.11.26: Spamhaus DQS and blacklistmaster belong to the node, not to a project).
 */
class DropProjectSecrets extends Migration
{
    public function up(): void
    {
        $this->db->query('DROP TABLE IF EXISTS project_secrets');
    }

    public function down(): void
    {
        // not restored
    }
}
