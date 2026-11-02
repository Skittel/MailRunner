<?php

namespace App\Database\Migrations;

use App\Libraries\PublicId;
use CodeIgniter\Database\Migration;

/** Random public ids (xxxx-xxxx) for nodes, customers and projects; URLs use them instead of the numeric ids. */
class PublicIds extends Migration
{
    public function up(): void
    {
        foreach (PublicId::TABLES as $t) {
            $this->db->query("ALTER TABLE {$t} ADD COLUMN public_id CHAR(9) NULL AFTER id");
            foreach ($this->db->table($t)->select('id')->get()->getResultArray() as $row) {
                $this->db->table($t)->where('id', $row['id'])->update(['public_id' => PublicId::generate($t)]);
            }
            $this->db->query("ALTER TABLE {$t} MODIFY public_id CHAR(9) NOT NULL, ADD UNIQUE KEY uq_{$t}_public_id (public_id)");
        }
    }

    public function down(): void
    {
        foreach (PublicId::TABLES as $t) {
            $this->db->query("ALTER TABLE {$t} DROP INDEX uq_{$t}_public_id, DROP COLUMN public_id");
        }
    }
}
