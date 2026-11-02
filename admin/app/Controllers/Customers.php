<?php

namespace App\Controllers;

use App\Libraries\PublicId;

use App\Libraries\Audit;
use App\Libraries\Clock;
use App\Libraries\Sync;

/** Customers (central). A change is pushed to every node with projects of the customer. */
class Customers extends BaseController
{
    public const KEY = '/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/';

    public function index()
    {
        $rows = db_connect()->query(
            'SELECT c.*, COUNT(p.id) AS projects, SUM(p.sync_error IS NOT NULL) AS sync_errors
             FROM customers c LEFT JOIN projects p ON p.customer_id = c.id GROUP BY c.id ORDER BY c.customer_key',
        )->getResultArray();

        return view('customers/index', ['customers' => $rows]);
    }

    public function new()
    {
        return view('customers/form', ['customer' => null, 'errors' => []]);
    }

    public function create()
    {
        $d = $this->request->getPost(['customer_key', 'name']);
        $e = [];
        if (! preg_match(self::KEY, (string) $d['customer_key'])) {
            $e['customer_key'] = '3-40 Zeichen: a-z, 0-9, „-“ (nicht am Anfang/Ende)';
        } elseif (db_connect()->table('customers')->where('customer_key', $d['customer_key'])->countAllResults() > 0) {
            $e['customer_key'] = 'Diesen Kunden gibt es schon.';
        }
        if ($e !== []) {
            return view('customers/form', ['customer' => $d, 'errors' => $e]);
        }
        $now = Clock::now();
        db_connect()->table('customers')->insert(['public_id' => PublicId::generate('customers'), 'customer_key' => $d['customer_key'], 'name' => trim((string) $d['name']) ?: $d['customer_key'],
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) db_connect()->insertID();
        Audit::log('customer.created', $d['customer_key']);

        return redirect()->to(site_url('customers/' . self::pid('customers', $id)))->with('ok', 'Kunde angelegt. Er wird auf eine Node übertragen, sobald er dort ein Projekt hat.');
    }

    public function show(string $pid)
    {
        $id = PublicId::id('customers', $pid);
        $c = $this->find($id);

        return view('customers/show', ['customer' => $c, 'projects' => db_connect()->table('projects p')->select('p.*, n.node_key')
            ->join('nodes n', 'n.id = p.node_id')->where('p.customer_id', $id)->orderBy('p.project_key')->get()->getResultArray()]);
    }

    public function update(string $pid)
    {
        $id = PublicId::id('customers', $pid);
        $c = $this->find($id);
        db_connect()->table('customers')->where('id', $id)->update([
            'name' => trim((string) $this->request->getPost('name')) ?: $c['customer_key'],
            'enabled' => $this->request->getPost('enabled') ? 1 : 0, 'updated_at' => Clock::now(),
        ]);
        Audit::log('customer.updated', $c['customer_key']);
        $err = (new Sync(config('Admin')))->pushCustomer($id);

        return redirect()->to(site_url('customers/' . self::pid('customers', $id)))->with($err === null ? 'ok' : 'error', $err === null ? 'Gespeichert und übertragen.' : 'Gespeichert, Übertragung fehlgeschlagen: ' . $err);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return db_connect()->table('customers')->where('id', $id)->get()->getRowArray()
            ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    private static function pid(string $table, int $id): string
    {
        return (string) db_connect()->table($table)->select('public_id')->where('id', $id)->get()->getRow('public_id');
    }
}
