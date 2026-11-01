<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Decision E-11/E-12: mail and analysis are files (encrypted, store/), the database keeps
 * envelope, From, To, Subject, Date, attachments (name, size) and the file paths.
 */
class ReceiveMessagesFiles extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE receive_messages
                DROP COLUMN data_enc,
                DROP COLUMN raw_stored,
                ADD COLUMN helo            VARCHAR(255)  NOT NULL DEFAULT '' AFTER client_ip,
                ADD COLUMN client_name     VARCHAR(255)  NOT NULL DEFAULT '' AFTER helo,
                ADD COLUMN header_from     VARCHAR(998)  NULL AFTER client_name,
                ADD COLUMN header_to       TEXT          NULL AFTER header_from,
                ADD COLUMN subject         VARCHAR(998)  NULL AFTER header_to,
                ADD COLUMN date_hdr        VARCHAR(255)  NULL AFTER subject,
                ADD COLUMN message_id_hdr  VARCHAR(998)  NULL AFTER date_hdr,
                ADD COLUMN attachments     JSON          NULL COMMENT '[{name,size,content_type,disposition}]' AFTER message_id_hdr,
                ADD COLUMN spf_status      VARCHAR(16)   NULL AFTER attachments,
                ADD COLUMN dkim_status     VARCHAR(16)   NULL AFTER spf_status,
                ADD COLUMN analysis_status ENUM('ok','timeout') NOT NULL DEFAULT 'ok' AFTER dkim_status,
                ADD COLUMN analysis_ms     INT UNSIGNED  NULL AFTER analysis_status,
                ADD COLUMN analysis_path   VARCHAR(255)  NULL COMMENT 'relative to store/, NULL after deletion' AFTER analysis_ms,
                ADD COLUMN raw_path        VARCHAR(255)  NULL COMMENT 'relative to store/, NULL if not kept' AFTER analysis_path
            SQL);
    }

    public function down(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE receive_messages
                DROP COLUMN helo, DROP COLUMN client_name, DROP COLUMN header_from, DROP COLUMN header_to,
                DROP COLUMN subject, DROP COLUMN date_hdr, DROP COLUMN message_id_hdr, DROP COLUMN attachments,
                DROP COLUMN spf_status, DROP COLUMN dkim_status, DROP COLUMN analysis_status, DROP COLUMN analysis_ms,
                DROP COLUMN analysis_path, DROP COLUMN raw_path,
                ADD COLUMN raw_stored TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN data_enc MEDIUMBLOB NULL
            SQL);
    }
}
