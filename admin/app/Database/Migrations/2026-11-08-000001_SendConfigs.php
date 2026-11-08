<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Central send configuration (decision E-27, after step 7; operator decision 08.11.2026: kept here encrypted):
 * the customer's application changes domains, profiles and accesses on a node; the admin takes the full set over
 * (DKIM private keys, token and password hashes; encrypted with the admin secrets key) and puts it on every node
 * of the project. send_config_nodes: what each node had at the last sync, to tell a local change from a stale node.
 */
class SendConfigs extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE send_configs (
                project_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                hash            CHAR(64)        NOT NULL COMMENT 'configuration hash as the node computes it',
                config_enc      MEDIUMBLOB      NOT NULL COMMENT 'full set incl. secrets, XChaCha20-Poly1305 with the admin secrets key',
                counts          JSON            NOT NULL COMMENT 'domains, profiles, accesses (no secrets)',
                source_node_id  BIGINT UNSIGNED NULL COMMENT 'node the set was last taken over from',
                imported_at     DATETIME(6)     NOT NULL,
                CONSTRAINT fk_send_configs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE send_config_nodes (
                project_id    BIGINT UNSIGNED NOT NULL,
                node_id       BIGINT UNSIGNED NOT NULL,
                hash          CHAR(64)        NULL COMMENT 'hash the node had after the last import or push',
                pending_hash  CHAR(64)        NULL COMMENT 'pushed, waiting for approval on the node (manual mode)',
                synced_at     DATETIME(6)     NULL,
                checked_at    DATETIME(6)     NULL,
                error         VARCHAR(500)    NULL,
                PRIMARY KEY (project_id, node_id),
                CONSTRAINT fk_send_config_nodes_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_send_config_nodes_node FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE send_config_nodes');
        $this->db->query('DROP TABLE send_configs');
    }
}
