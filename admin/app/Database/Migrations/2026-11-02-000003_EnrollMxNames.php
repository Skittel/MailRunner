<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** The enrollment carries the MX names; the node gets its whole configuration from the admin. */
class EnrollMxNames extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE node_enrollments ADD COLUMN mx_names VARCHAR(500) NOT NULL DEFAULT '' AFTER hostname");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE node_enrollments DROP COLUMN mx_names');
    }
}
