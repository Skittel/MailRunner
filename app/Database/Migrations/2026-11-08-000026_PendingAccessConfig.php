<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Central access configuration (send and receive projects): in manual mode it waits for approval (kind
 * access_config, encrypted with the node key). Kind send_config stays for changes queued by older admins.
 */
class PendingAccessConfig extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets','send_config','send_access_lock','access_config') NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM admin_pending_changes WHERE kind = 'access_config'");
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets','send_config','send_access_lock') NOT NULL");
    }
}
