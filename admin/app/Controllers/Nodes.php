<?php

namespace App\Controllers;

use App\Libraries\PublicId;

use App\Libraries\Audit;
use App\Libraries\Clock;
use App\Libraries\Health;
use App\Libraries\Sync;
use Throwable;

/** Dashboard and nodes: overview with health, last self test, polling, import of node configuration. */
class Nodes extends BaseController
{
    private function sync(): Sync
    {
        return new Sync(config('Admin'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodesWithHealth(): array
    {
        $nodes = db_connect()->table('nodes')->orderBy('node_key')->get()->getResultArray();
        foreach ($nodes as &$n) {
            $n['health'] = Health::of($n);
            $n['status'] = json_decode((string) ($n['last_status'] ?? ''), true) ?: [];
        }

        return $nodes;
    }

    public function dashboard()
    {
        $db = db_connect();

        return view('dashboard', [
            'nodes'     => $this->nodesWithHealth(),
            'customers' => $db->table('customers')->countAllResults(),
            'projects'  => $db->table('projects')->countAllResults(),
            'unsynced'  => $db->table('projects')->where('sync_error IS NOT NULL')->countAllResults(),
        ]);
    }

    /** htmx partial: node cards (auto refresh). */
    public function cards()
    {
        return view('nodes/_cards', ['nodes' => $this->nodesWithHealth()]);
    }

    public function index()
    {
        return view('nodes/index', ['nodes' => $this->nodesWithHealth()]);
    }

    public function new()
    {
        return view('nodes/form', ['node' => null, 'errors' => []]);
    }

    public function create()
    {
        $data   = $this->request->getPost(['node_key', 'name', 'base_url']);
        $errors = $this->validateNode($data);
        if ($errors !== []) {
            return view('nodes/form', ['node' => $data, 'errors' => $errors]);
        }
        $now = Clock::now();
        db_connect()->table('nodes')->insert(['public_id' => PublicId::generate('nodes'),
            'node_key' => $data['node_key'], 'name' => trim($data['name']) ?: $data['node_key'], 'base_url' => rtrim($data['base_url'], '/'),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) db_connect()->insertID();
        Audit::log('node.created', $data['node_key'], ['base_url' => $data['base_url']]);
        $this->sync()->poll(db_connect()->table('nodes')->where('id', $id)->get()->getRowArray());

        return redirect()->to(site_url('nodes/' . self::pid('nodes', $id)))->with('ok', 'Node angelegt.');
    }

    public function show(string $pid)
    {
        $id = PublicId::id('nodes', $pid);
        $node = $this->find($id);

        return view('nodes/show', ['node' => $node, 'projects' => db_connect()->table('projects p')->select('p.*, c.customer_key')
            ->join('customers c', 'c.id = p.customer_id')->where('p.node_id', $id)->orderBy('c.customer_key')->orderBy('p.project_key')->get()->getResultArray()]);
    }

    public function poll(string $pid)
    {
        $id = PublicId::id('nodes', $pid);
        $this->sync()->poll(db_connect()->table('nodes')->where('id', $id)->get()->getRowArray());
        $node = $this->find($id);

        return $this->request->hasHeader('HX-Request') ? view('nodes/_card', ['n' => $node, 'detail' => (bool) $this->request->getGet('detail')]) : redirect()->to(site_url('nodes/' . self::pid('nodes', $id)));
    }

    /** htmx partial: newest journal events of the node (live from the node). */
    public function journal(string $pid)
    {
        $id = PublicId::id('nodes', $pid);
        $node  = $this->find($id);
        $event = preg_replace('/[^a-z_.,]/', '', (string) $this->request->getGet('event'));
        try {
            $page = $this->sync()->client($node)->ok('GET', 'journal?latest=1&limit=50' . ($event !== '' ? '&event=' . $event : ''));
        } catch (Throwable $e) {
            return '<div class="alert alert-danger">Journal nicht abrufbar: ' . esc($e->getMessage()) . '</div>';
        }

        return view('nodes/_journal', ['events' => $page['data']]);
    }

    public function import(string $pid)
    {
        $id = PublicId::id('nodes', $pid);
        $node = $this->find($id);
        try {
            $stats = $this->sync()->import($node);
        } catch (Throwable $e) {
            return redirect()->to(site_url('nodes/' . self::pid('nodes', $id)))->with('error', 'Import fehlgeschlagen: ' . $e->getMessage());
        }
        Audit::log('node.imported', $node['node_key'], $stats);

        return redirect()->to(site_url('nodes/' . self::pid('nodes', $id)))->with('ok', sprintf('Übernommen: %d Kunden, %d Projekte.', $stats['customers'], $stats['projects']));
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        $node = db_connect()->table('nodes')->where('id', $id)->get()->getRowArray();
        if ($node === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $node['health'] = Health::of($node);
        $node['status'] = json_decode((string) ($node['last_status'] ?? ''), true) ?: [];

        return $node;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, string>
     */
    private function validateNode(array $d): array
    {
        $e = [];
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/', (string) $d['node_key'])) {
            $e['node_key'] = 'Node-ID wie bei der Installation (--node-id), z. B. node1.eu';
        } elseif (db_connect()->table('nodes')->where('node_key', $d['node_key'])->countAllResults() > 0) {
            $e['node_key'] = 'Diese Node gibt es schon.';
        }
        if (! preg_match('#^https://[a-z0-9.-]+(:\d+)?/?$#', (string) $d['base_url'])) {
            $e['base_url'] = 'https://<Hostname der Node>';
        }

        return $e;
    }

    private static function pid(string $table, int $id): string
    {
        return (string) db_connect()->table($table)->select('public_id')->where('id', $id)->get()->getRow('public_id');
    }
}
