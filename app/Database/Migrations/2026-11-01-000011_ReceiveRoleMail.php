<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Mail to postmaster@/abuse@ (decision E-18): accepted for every project domain, original kept.
 * - receive_messages.role: postmaster | abuse | NULL
 * - projects.role_mail: delivery of these mails (default = to the node operator, api = customer collects,
 *   forward = to role_mail_forward_to). Forwarding needs the send role (TODO).
 */
class ReceiveRoleMail extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE receive_messages
                ADD COLUMN role VARCHAR(16) NULL AFTER profile,
                ADD KEY idx_receive_messages_role (role)
            SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE projects
                ADD COLUMN role_mail ENUM('default','api','forward') NOT NULL DEFAULT 'default' AFTER journal_scope,
                ADD COLUMN role_mail_forward_to VARCHAR(320) NULL AFTER role_mail
            SQL);
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE projects DROP COLUMN role_mail_forward_to, DROP COLUMN role_mail');
        $this->db->query('ALTER TABLE receive_messages DROP KEY idx_receive_messages_role, DROP COLUMN role');
    }
}
