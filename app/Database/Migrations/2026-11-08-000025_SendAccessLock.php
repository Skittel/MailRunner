<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Admin lock of a send access (E-27): the operator stops an access (e.g. abuse); the customer sees the lock and its
 * reason but cannot lift it. In manual mode the lock waits for approval (kind send_access_lock).
 */
class SendAccessLock extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE send_accesses ADD locked_at DATETIME(6) NULL AFTER enabled, ADD locked_reason VARCHAR(200) NULL AFTER locked_at');
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets','send_config','send_access_lock') NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM admin_pending_changes WHERE kind = 'send_access_lock'");
        $this->db->query("ALTER TABLE admin_pending_changes MODIFY kind ENUM('customer','project','token_revoke','secrets','send_config') NOT NULL");
        $this->forge->dropColumn('send_accesses', ['locked_at', 'locked_reason']);
    }
}
