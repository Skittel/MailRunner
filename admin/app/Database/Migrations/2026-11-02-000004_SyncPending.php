<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A node with MAILRUNNER_ADMIN_CONFIG=manual queues pushed configuration until it is approved there. */
class SyncPending extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE projects ADD COLUMN sync_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER sync_error');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE projects DROP COLUMN sync_pending');
    }
}
