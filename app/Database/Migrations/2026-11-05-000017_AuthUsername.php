<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Login name used for SMTP AUTH next to the credential id; analysis_status "error" for authenticated mail that is
 * stored although the analysis failed (an authenticated mail is always accepted).
 */
class AuthUsername extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE receive_messages
            ADD COLUMN auth_username VARCHAR(100) NULL COMMENT 'SMTP AUTH login for received_via=auth' AFTER auth_credential_id,
            MODIFY COLUMN analysis_status ENUM('ok','timeout','error') NOT NULL DEFAULT 'ok'");
    }

    public function down(): void
    {
        $this->db->query("UPDATE receive_messages SET analysis_status = 'timeout' WHERE analysis_status = 'error'");
        $this->db->query("ALTER TABLE receive_messages DROP COLUMN auth_username,
            MODIFY COLUMN analysis_status ENUM('ok','timeout') NOT NULL DEFAULT 'ok'");
    }
}
