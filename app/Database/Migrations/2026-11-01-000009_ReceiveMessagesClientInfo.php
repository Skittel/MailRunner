<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Facts about the client IP from ipregistry (AS, country, city, continent, carrier, threat).
 */
class ReceiveMessagesClientInfo extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE receive_messages
                ADD COLUMN client_asn       INT UNSIGNED NULL AFTER client_name,
                ADD COLUMN client_as_org    VARCHAR(255) NULL AFTER client_asn,
                ADD COLUMN client_country   CHAR(2)      NULL AFTER client_as_org,
                ADD COLUMN client_city      VARCHAR(128) NULL AFTER client_country,
                ADD COLUMN client_continent CHAR(2)      NULL AFTER client_city,
                ADD COLUMN client_carrier   VARCHAR(128) NULL AFTER client_continent,
                ADD COLUMN client_is_threat TINYINT(1)   NULL AFTER client_carrier,
                ADD KEY idx_receive_messages_client_asn (client_asn),
                ADD KEY idx_receive_messages_client_country (client_country)
            SQL);
    }

    public function down(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE receive_messages
                DROP KEY idx_receive_messages_client_asn, DROP KEY idx_receive_messages_client_country,
                DROP COLUMN client_asn, DROP COLUMN client_as_org, DROP COLUMN client_country, DROP COLUMN client_city,
                DROP COLUMN client_continent, DROP COLUMN client_carrier, DROP COLUMN client_is_threat
            SQL);
    }
}
