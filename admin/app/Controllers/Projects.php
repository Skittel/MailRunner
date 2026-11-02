<?php

namespace App\Controllers;

use App\Libraries\PublicId;

use App\Libraries\Audit;
use App\Libraries\Clock;
use App\Libraries\Sync;
use Throwable;

/** Projects of a customer on a node: desired configuration here, pushed to the node on every save. */
class Projects extends BaseController
{
    public const PROFILES      = ['analysis' => 'Analyse (alles annehmen, analysieren)', 'checked' => 'Geprüft', 'checked_auth' => 'Geprüft + SMTP-Anmeldung'];
    public const JOURNAL_SCOPE = ['own' => 'nur eigene', 'connections' => '+ Verbindungen ohne MAIL FROM', 'unassigned' => '+ alle ohne Projekt'];
    public const ROLE_MAIL     = ['default' => 'an den Betreiber (folgt)', 'api' => 'Abruf per API', 'forward' => 'Weiterleitung (folgt)'];

    private function sync(): Sync
    {
        return new Sync(config('Admin'));
    }

    public function new(string $customerPid)
    {
        $customerId = PublicId::id('customers', $customerPid);
        $c = db_connect()->table('customers')->where('id', $customerId)->get()->getRowArray() ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();

        return view('projects/form', ['customer' => $c, 'project' => null, 'errors' => [], 'nodes' => $this->nodes()]);
    }

    public function create(string $customerPid)
    {
        $customerId = PublicId::id('customers', $customerPid);
        $c = db_connect()->table('customers')->where('id', $customerId)->get()->getRowArray() ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        [$row, $errors] = $this->fromForm(null, $customerId);
        if ($errors !== []) {
            return view('projects/form', ['customer' => $c, 'project' => $row, 'errors' => $errors, 'nodes' => $this->nodes()]);
        }
        $now = Clock::now();
        db_connect()->table('projects')->insert($row + ['public_id' => PublicId::generate('projects'), 'customer_id' => $customerId, 'changed_at' => $now, 'created_at' => $now]);
        $id = (int) db_connect()->insertID();
        Audit::log('project.created', $c['customer_key'] . '/' . $row['project_key']);
        $err = $this->sync()->pushProject($id);

        return redirect()->to(site_url('projects/' . self::pid('projects', $id)))->with($err === null ? 'ok' : 'error', $err === null ? 'Projekt angelegt und auf die Node übertragen.' : 'Angelegt, Übertragung fehlgeschlagen: ' . $err);
    }

    public function show(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p = $this->find($id);

        return view('projects/form', ['customer' => ['id' => $p['customer_id'], 'public_id' => $p['customer_public_id'], 'customer_key' => $p['customer_key'], 'name' => $p['customer_name']],
            'project' => $p, 'errors' => [], 'nodes' => $this->nodes()]);
    }

    public function update(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p = $this->find($id);
        [$row, $errors] = $this->fromForm($p, (int) $p['customer_id']);
        if ($errors !== []) {
            return view('projects/form', ['customer' => ['id' => $p['customer_id'], 'public_id' => $p['customer_public_id'], 'customer_key' => $p['customer_key'], 'name' => $p['customer_name']],
                'project' => $row + $p, 'errors' => $errors, 'nodes' => $this->nodes()]);
        }
        db_connect()->table('projects')->where('id', $id)->update($row + ['changed_at' => Clock::now()]);
        Audit::log('project.updated', $p['customer_key'] . '/' . $p['project_key']);
        $err = $this->sync()->pushProject($id);

        return redirect()->to(site_url('projects/' . self::pid('projects', $id)))->with($err === null ? 'ok' : 'error', $err === null ? 'Gespeichert und übertragen.' : 'Gespeichert, Übertragung fehlgeschlagen: ' . $err);
    }

    public function push(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p   = $this->find($id);
        $err = $this->sync()->pushProject($id);
        Audit::log('project.pushed', $p['customer_key'] . '/' . $p['project_key'], ['error' => $err]);

        return redirect()->to(site_url('projects/' . self::pid('projects', $id)))->with($err === null ? 'ok' : 'error', $err === null ? 'Übertragen.' : 'Übertragung fehlgeschlagen: ' . $err);
    }

    /** htmx partial: API tokens of the project (live from the node). */
    public function tokens(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p = $this->find($id);
        try {
            $data = $this->sync()->client($this->node($p))->ok('GET', 'projects/' . $p['customer_key'] . '/' . $p['project_key'])['data'];
        } catch (Throwable $e) {
            return '<div class="alert alert-warning">Tokens nicht abrufbar: ' . esc($e->getMessage()) . '</div>';
        }

        return view('projects/_tokens', ['project' => $p, 'tokens' => $data['tokens'], 'created' => null]);
    }

    public function createToken(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p     = $this->find($id);
        $label = trim((string) $this->request->getPost('label'));
        $days  = (int) $this->request->getPost('expires_days');
        try {
            $client  = $this->sync()->client($this->node($p));
            $created = $client->ok('POST', 'projects/' . $p['customer_key'] . '/' . $p['project_key'] . '/tokens', ['label' => $label, 'expires_days' => $days ?: null])['data'];
            $tokens  = $client->ok('GET', 'projects/' . $p['customer_key'] . '/' . $p['project_key'])['data']['tokens'];
        } catch (Throwable $e) {
            return '<div class="alert alert-danger">Token nicht angelegt: ' . esc($e->getMessage()) . '</div>';
        }
        Audit::log('token.created', $p['customer_key'] . '/' . $p['project_key'], ['token_id' => $created['token_id'], 'label' => $label]);

        return view('projects/_tokens', ['project' => $p, 'tokens' => $tokens, 'created' => $created]);
    }

    public function revokeToken(string $pid, string $tokenId)
    {
        $id = PublicId::id('projects', $pid);
        $p = $this->find($id);
        try {
            $client = $this->sync()->client($this->node($p));
            $client->ok('DELETE', 'tokens/' . rawurlencode($tokenId));
            $tokens = $client->ok('GET', 'projects/' . $p['customer_key'] . '/' . $p['project_key'])['data']['tokens'];
        } catch (Throwable $e) {
            return '<div class="alert alert-danger">Widerruf fehlgeschlagen: ' . esc($e->getMessage()) . '</div>';
        }
        Audit::log('token.revoked', $p['customer_key'] . '/' . $p['project_key'], ['token_id' => $tokenId]);

        return view('projects/_tokens', ['project' => $p, 'tokens' => $tokens, 'created' => null]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function fromForm(?array $existing, int $customerId): array
    {
        $f      = $this->request->getPost();
        $errors = [];
        $routes = array_values(array_unique(array_filter(array_map(static fn ($l) => strtolower(trim($l)), preg_split('/[\s,]+/', (string) ($f['routes'] ?? '')) ?: []))));
        foreach ($routes as $r) {
            if (! preg_match('/^([a-z0-9._%+-]+@)?([a-z0-9-]+\.)+[a-z0-9-]{2,}$/', $r)) {
                $errors['routes'] = 'Ungültige Route: ' . $r;
            }
        }
        $settings = [
            'recipient_pattern'      => trim((string) ($f['recipient_pattern'] ?? '')) ?: null,
            'recipient_always_allow' => array_values(array_filter(array_map('trim', explode(',', (string) ($f['always_allow'] ?? ''))))) ?: null,
            'delete_after_ack_hours' => (int) ($f['delete_after_ack_hours'] ?? 24),
            'unacked_days'           => (int) ($f['unacked_days'] ?? 14),
            'metadata_days'          => (int) ($f['metadata_days'] ?? 30),
            'journal_scope'          => array_key_exists($f['journal_scope'] ?? '', self::JOURNAL_SCOPE) ? $f['journal_scope'] : 'own',
            'role_mail'              => array_key_exists($f['role_mail'] ?? '', self::ROLE_MAIL) ? $f['role_mail'] : 'default',
            'role_mail_forward_to'   => trim((string) ($f['role_mail_forward_to'] ?? '')) ?: null,
        ];
        if ($settings['recipient_pattern'] !== null && @preg_match('/' . str_replace('/', '\/', $settings['recipient_pattern']) . '/', '') === false) {
            $errors['recipient_pattern'] = 'Kein gültiger regulärer Ausdruck.';
        }
        if ($settings['role_mail'] === 'forward' && ($settings['role_mail_forward_to'] === null || ! filter_var($settings['role_mail_forward_to'], FILTER_VALIDATE_EMAIL))) {
            $errors['role_mail_forward_to'] = 'Für „Weiterleitung“ eine gültige Adresse angeben.';
        }
        $row = ['name' => trim((string) ($f['name'] ?? '')), 'enabled' => ! empty($f['enabled']) ? 1 : 0,
            'routes' => json_encode($routes), 'settings' => json_encode($settings)];
        if ($existing === null) {
            $row['project_key'] = (string) ($f['project_key'] ?? '');
            $row['profile']     = array_key_exists($f['profile'] ?? '', self::PROFILES) ? $f['profile'] : 'analysis';
            $row['node_id']     = (int) db_connect()->table('nodes')->select('id')->where('public_id', (string) ($f['node_id'] ?? ''))->get()->getRow('id');
            if (! preg_match(Customers::KEY, $row['project_key'])) {
                $errors['project_key'] = '3-40 Zeichen: a-z, 0-9, „-“';
            } elseif (db_connect()->table('projects')->where('customer_id', $customerId)->where('project_key', $row['project_key'])->countAllResults() > 0) {
                $errors['project_key'] = 'Dieses Projekt gibt es schon.';
            }
            if (db_connect()->table('nodes')->where('id', $row['node_id'])->countAllResults() === 0) {
                $errors['node_id'] = 'Bitte eine Node wählen.';
            }
        }
        $row['name'] = $row['name'] ?: ($existing['project_key'] ?? $row['project_key'] ?? '');

        return [$row, $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return db_connect()->table('projects p')->select('p.*, c.customer_key, c.public_id AS customer_public_id, c.name AS customer_name, n.node_key, n.name AS node_name')
            ->join('customers c', 'c.id = p.customer_id')->join('nodes n', 'n.id = p.node_id')->where('p.id', $id)->get()->getRowArray()
            ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    private function node(array $p): array
    {
        return db_connect()->table('nodes')->where('id', $p['node_id'])->get()->getRowArray();
    }

    private function nodes(): array
    {
        return db_connect()->table('nodes')->where('enabled', 1)->orderBy('node_key')->get()->getResultArray();
    }

    private static function pid(string $table, int $id): string
    {
        return (string) db_connect()->table($table)->select('public_id')->where('id', $id)->get()->getRow('public_id');
    }
}
