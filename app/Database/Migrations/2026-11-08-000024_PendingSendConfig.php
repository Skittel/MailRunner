<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Central send configuration (E-27): in manual mode a send configuration from the admin waits for approval
 * (kind send_config, DKIM keys and hashes encrypted with the node key).
 */
class PendingSendConfig extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets','send_config') NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM admin_pending_changes WHERE kind = 'send_config'");
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets') NOT NULL");
    }
}
