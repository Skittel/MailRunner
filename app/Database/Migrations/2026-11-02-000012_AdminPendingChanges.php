<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Configuration changes from MailRunner Admin that wait for approval on the node (MAILRUNNER_ADMIN_CONFIG=manual,
 * mr:config:apply). One open row per object: a newer change replaces the older one.
 */
class AdminPendingChanges extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE admin_pending_changes (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                kind        ENUM('customer','project','token_revoke') NOT NULL,
                ref         VARCHAR(200) NOT NULL COMMENT 'customer key, customer/project or token id',
                payload     JSON         NOT NULL,
                actor       VARCHAR(255) NOT NULL,
                received_at DATETIME(6)  NOT NULL,
                decided_at  DATETIME(6)  NULL,
                decision    ENUM('applied','discarded','failed') NULL,
                decided_by  VARCHAR(255) NULL,
                result      VARCHAR(500) NULL,
                KEY idx_pending_open (decided_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS admin_pending_changes');
    }
}
