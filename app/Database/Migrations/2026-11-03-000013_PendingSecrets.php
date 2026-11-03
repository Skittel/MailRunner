<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Common secrets from MailRunner Admin can wait for approval too (payload encrypted with the node key). */
class PendingSecrets extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets') NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM admin_pending_changes WHERE kind = 'secrets'");
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke') NOT NULL");
    }
}
