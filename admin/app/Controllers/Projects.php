<?php

namespace App\Controllers;

use App\Libraries\PublicId;

use App\Libraries\Audit;
use App\Libraries\Clock;
use App\Libraries\Sync;
use App\Libraries\Tokens;
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

        $moveOld = $p['move_from_node_id'] ? ['node' => db_connect()->table('nodes')->where('id', $p['move_from_node_id'])->get()->getRowArray(),
            'state' => $this->sync()->projectOnNode($id, (int) $p['move_from_node_id'])] : null;

        return view('projects/form', ['customer' => ['id' => $p['customer_id'], 'public_id' => $p['customer_public_id'], 'customer_key' => $p['customer_key'], 'name' => $p['customer_name']],
            'project' => $p, 'errors' => [], 'nodes' => $this->nodes(), 'moveOld' => $moveOld]);
    }

    /**
     * Move step 1: the project is created on the target node (active) and becomes its node; the old node keeps
     * accepting until the MX records point to the new node and the application uses the new node's API.
     */
    public function move(string $pid)
    {
        $id     = PublicId::id('projects', $pid);
        $p      = $this->find($id);
        $ref    = $p['customer_key'] . '/' . $p['project_key'];
        $target = (int) db_connect()->table('nodes')->select('id')->where('public_id', (string) $this->request->getPost('node_id'))->get()->getRow('id');
        if ($p['move_from_node_id'] !== null || $target === 0 || $target === (int) $p['node_id']) {
            return redirect()->to(site_url('projects/' . $pid))->with('error', 'Bitte eine andere Node wählen (kein Umzug aktiv).');
        }
        if (! $this->sync()->ensureTokens($id)) { // tokens must be central before the move: same bearer on both nodes
            return redirect()->to(site_url('projects/' . $pid))->with('error', 'Umzug nicht gestartet: Die Tokens der bisherigen Node konnten nicht übernommen werden (Node nicht erreichbar oder zu alt).');
        }
        $db = db_connect();
        $db->table('projects')->where('id', $id)->update(['node_id' => $target, 'move_from_node_id' => $p['node_id'], 'changed_at' => Clock::now()]);
        $err = $this->sync()->pushProject($id);
        if ($err !== null) {
            $db->table('projects')->where('id', $id)->update(['node_id' => $p['node_id'], 'move_from_node_id' => null]);
            $this->sync()->pushProject($id);
            Audit::log('project.move_failed', $ref, ['error' => $err]);

            return redirect()->to(site_url('projects/' . $pid))->with('error', 'Umzug nicht gestartet, neue Node meldet: ' . $err);
        }
        Audit::log('project.move_started', $ref, ['from' => (int) $p['node_id'], 'to' => $target]);

        return redirect()->to(site_url('projects/' . $pid))->with('ok', 'Projekt auf der neuen Node angelegt. Die alte Node nimmt weiter an, bis du sie abschaltest.');
    }

    /** Move step 2: disable the project on the old node (after DNS switch and API switch of the application). */
    public function moveFinish(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p  = $this->find($id);
        if ($p['move_from_node_id'] === null) {
            return redirect()->to(site_url('projects/' . $pid));
        }
        $err = $this->sync()->pushProject($id, (int) $p['move_from_node_id'], false);
        if ($err !== null) {
            return redirect()->to(site_url('projects/' . $pid))->with('error', 'Alte Node nicht erreichbar: ' . $err);
        }
        db_connect()->table('projects')->where('id', $id)->update(['move_from_node_id' => null, 'changed_at' => Clock::now()]);
        Audit::log('project.move_finished', $p['customer_key'] . '/' . $p['project_key'], ['old_node' => (int) $p['move_from_node_id']]);

        return redirect()->to(site_url('projects/' . $pid))->with('ok', 'Umzug abgeschlossen: Das Projekt ist auf der alten Node deaktiviert.');
    }

    /** Cancel a move: disable on the new node, the old node is the project's node again. */
    public function moveCancel(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p  = $this->find($id);
        if ($p['move_from_node_id'] === null) {
            return redirect()->to(site_url('projects/' . $pid));
        }
        $errNew = $this->sync()->pushProject($id, (int) $p['node_id'], false);
        db_connect()->table('projects')->where('id', $id)->update(['node_id' => $p['move_from_node_id'], 'move_from_node_id' => null, 'changed_at' => Clock::now()]);
        $errOld = $this->sync()->pushProject($id);
        Audit::log('project.move_cancelled', $p['customer_key'] . '/' . $p['project_key'], ['new_node_error' => $errNew, 'old_node_error' => $errOld]);

        return redirect()->to(site_url('projects/' . $pid))->with($errNew === null && $errOld === null ? 'ok' : 'error',
            $errNew === null && $errOld === null ? 'Umzug abgebrochen.' : 'Umzug abgebrochen, aber: ' . trim(($errNew ?? '') . ' ' . ($errOld ?? '')));
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
        $err = $this->sync()->pushEverywhere($id);

        return redirect()->to(site_url('projects/' . self::pid('projects', $id)))->with($err === null ? 'ok' : 'error', $err === null ? 'Gespeichert und übertragen.' : 'Gespeichert, Übertragung fehlgeschlagen: ' . $err);
    }

    public function push(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p   = $this->find($id);
        $err = $this->sync()->pushEverywhere($id);
        Audit::log('project.pushed', $p['customer_key'] . '/' . $p['project_key'], ['error' => $err]);

        return redirect()->to(site_url('projects/' . self::pid('projects', $id)))->with($err === null ? 'ok' : 'error', $err === null ? 'Übertragen.' : 'Übertragung fehlgeschlagen: ' . $err);
    }

    /** htmx partial: API tokens of the project (kept here, last use read live from the project's nodes). */
    public function tokens(string $pid)
    {
        $id = PublicId::id('projects', $pid);

        return $this->tokensView($this->find($id), null, null);
    }

    public function createToken(string $pid)
    {
        $id = PublicId::id('projects', $pid);
        $p  = $this->find($id);
        if ($p['customer_key'] === Tokens::LOCAL_CUSTOMER) {
            return '<div class="alert alert-warning">Self-Test-Projekte haben lokale Tokens auf jeder Node (install.sh).</div>';
        }
        if (! $this->sync()->ensureTokens($id)) {
            return '<div class="alert alert-danger">Token nicht angelegt: Die Node des Projekts ist nicht erreichbar oder zu alt für zentrale Tokens.</div>';
        }
        try {
            $days    = (int) $this->request->getPost('expires_days');
            $created = (new Tokens())->create($id, (string) $this->request->getPost('label'), $days ?: null, session('user_id'));
        } catch (Throwable $e) {
            return '<div class="alert alert-danger">Token nicht angelegt: ' . esc($e->getMessage()) . '</div>';
        }
        Audit::log('token.created', $p['customer_key'] . '/' . $p['project_key'], ['token_id' => $created['token_id']]);

        return $this->tokensView($p, $created, $this->sync()->pushEverywhere($id));
    }

    public function revokeToken(string $pid, string $tokenId)
    {
        $id = PublicId::id('projects', $pid);
        $p  = $this->find($id);
        if (! (new Tokens())->revoke($id, $tokenId)) {
            return $this->tokensView($p, null, 'Token unbekannt oder schon gesperrt.');
        }
        Audit::log('token.revoked', $p['customer_key'] . '/' . $p['project_key'], ['token_id' => $tokenId]);

        return $this->tokensView($p, null, $this->sync()->pushEverywhere($id));
    }

    /** @param array<string, mixed>|null $created */
    private function tokensView(array $p, ?array $created, ?string $error)
    {
        $local    = $p['customer_key'] === Tokens::LOCAL_CUSTOMER;
        $tokens   = $local ? array_map(static fn ($t) => $t + ['origin' => 'local'], array_reverse($this->sync()->projectOnNode((int) $p['id'], (int) $p['node_id'])['tokens'] ?? []))
            : (new Tokens())->list((int) $p['id']);
        $lastUsed = [];
        $nodeIds  = array_filter([(int) $p['node_id'], (int) ($p['move_from_node_id'] ?? 0)]);
        foreach ($nodeIds as $nid) {
            foreach ($this->sync()->projectOnNode((int) $p['id'], $nid)['tokens'] ?? [] as $t) {
                if ($t['last_used_at'] !== null && ($lastUsed[$t['token_id']] ?? '') < $t['last_used_at']) {
                    $lastUsed[$t['token_id']] = $t['last_used_at'];
                }
            }
        }

        return view('projects/_tokens', ['project' => $p, 'tokens' => $tokens, 'lastUsed' => $lastUsed, 'created' => $created,
            'error' => $error, 'imported' => $local || $p['tokens_imported_at'] !== null, 'local' => $local]);
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
