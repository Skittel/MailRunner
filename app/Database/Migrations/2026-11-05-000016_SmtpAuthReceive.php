<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Mail delivered with SMTP AUTH (ports 25/587/465) is stored like MX mail; these columns say how it came in.
 */
class SmtpAuthReceive extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE receive_messages
            ADD COLUMN received_via VARCHAR(8) NOT NULL DEFAULT 'mx' COMMENT 'mx | auth' AFTER profile,
            ADD COLUMN auth_credential_id VARCHAR(32) NULL COMMENT 'smtp_credentials.credential_id for received_via=auth' AFTER received_via,
            ADD COLUMN smtp_service VARCHAR(16) NULL COMMENT 'smtp (25) | submission (587) | smtps (465)' AFTER auth_credential_id");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE receive_messages DROP COLUMN received_via, DROP COLUMN auth_credential_id, DROP COLUMN smtp_service');
    }
}
