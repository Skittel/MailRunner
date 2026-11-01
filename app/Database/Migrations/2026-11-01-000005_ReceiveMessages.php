<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Received messages, one row per message and recipient.
 * Content (header data, analysis JSON) is stored encrypted with the node key in data_enc.
 */
class ReceiveMessages extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE receive_messages (
                id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message_id         VARCHAR(32)     NOT NULL COMMENT 'public id, e.g. mrm_...',
                project_id         BIGINT UNSIGNED NOT NULL,
                spool_id           VARCHAR(40)     NOT NULL COMMENT 'idempotent import key',
                profile            ENUM('analysis','checked','checked_auth') NOT NULL,
                status             ENUM('analyzed','accepted','rejected','pending','blocked') NOT NULL,
                received_at        DATETIME(6)     NOT NULL,
                imported_at        DATETIME(6)     NOT NULL,
                queue_id           VARCHAR(32)     NOT NULL DEFAULT '',
                mail_from          VARCHAR(320)    NOT NULL DEFAULT '' COMMENT 'SMTP MAIL FROM, empty = null sender',
                rcpt               VARCHAR(320)    NOT NULL,
                client_ip          VARCHAR(45)     NOT NULL,
                size               INT UNSIGNED    NOT NULL,
                sha256             CHAR(64)        NOT NULL COMMENT 'of the raw mail as received',
                raw_stored         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '0 = raw mail deleted after analysis',
                data_enc           MEDIUMBLOB      NULL COMMENT 'encrypted JSON, NULL after content deletion',
                acked_at           DATETIME(6)     NULL,
                content_deleted_at DATETIME(6)     NULL,
                delete_reason      ENUM('ack_retention','expired','api','admin') NULL,
                UNIQUE KEY uq_receive_messages_message_id (message_id),
                UNIQUE KEY uq_receive_messages_spool_id (spool_id),
                KEY idx_receive_messages_project (project_id, id),
                KEY idx_receive_messages_retention (content_deleted_at, acked_at),
                CONSTRAINT fk_receive_messages_project FOREIGN KEY (project_id) REFERENCES projects (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS receive_messages');
    }
}
