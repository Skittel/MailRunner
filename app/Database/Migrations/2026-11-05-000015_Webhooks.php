<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Webhook per project, set by the customer over the API (PUT /v1/webhook): an empty POST to the URL when new
 * mail can be fetched, at most once per cooldown. pending_at marks mail that has not been announced yet.
 */
class Webhooks extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE project_webhooks (
                project_id       BIGINT UNSIGNED   NOT NULL PRIMARY KEY,
                url              VARCHAR(2048)     NOT NULL,
                cooldown_seconds SMALLINT UNSIGNED NOT NULL,
                created_at       DATETIME(6)       NOT NULL,
                updated_at       DATETIME(6)       NOT NULL,
                updated_by       VARCHAR(64)       NULL,
                pending_at       DATETIME(6)       NULL COMMENT 'oldest mail not announced yet',
                last_attempt_at  DATETIME(6)       NULL,
                last_status      VARCHAR(8)        NULL COMMENT 'sent|failed',
                last_http_code   SMALLINT UNSIGNED NULL,
                last_error       VARCHAR(32)       NULL,
                last_sent_at     DATETIME(6)       NULL,
                last_failed_at   DATETIME(6)       NULL,
                KEY idx_pending (pending_at),
                CONSTRAINT fk_webhooks_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE project_webhooks');
    }
}
