<?php

namespace App\Controllers\Admin\V1;

use App\Core\Support\Transaction;

use App\Core\Node\Updater;

use App\Core\Admin\PendingChanges;

use App\Core\Api\ApiResponse;
use App\Core\Node\NodeInfo;
use App\Core\Node\SelfTest;
use App\Core\Support\Time;

/**
 * GET /admin/v1/node     identity, version, roles, last self test (full), key figures
 * GET /admin/v1/journal  node-wide journal (all projects), ?after_id=&limit=&event=a,b; latest=1: newest first
 */
class Node extends AdminController
{
    public function show()
    {
        $db     = db_connect();
        $info   = (new NodeInfo(config('Mailrunner')))->toArray();
        $last   = $db->table('journal')->select('created_at, details')->where('event', SelfTest::EVENT)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $since  = Time::nowObject()->modify('-24 hours')->format(Time::DB_FORMAT);
        $counts = [
            'customers'           => $db->table('customers')->countAllResults(),
            'projects'            => $db->table('projects')->countAllResults(),
            'messages_24h'        => $db->table('receive_messages')->where('received_at >=', $since)->countAllResults(),
            'messages_unacked'    => $db->table('receive_messages')->where('acked_at', null)->where('content_deleted_at', null)->countAllResults(),
            'role_mails_unacked'  => $db->table('receive_messages')->where('role IS NOT NULL')->where('acked_at', null)->where('content_deleted_at', null)->countAllResults(),
            'smtp_sessions_24h'   => $db->table('journal')->whereIn('event', ['smtp.attempt', 'smtp.connection'])->where('created_at >=', $since)->countAllResults(),
        ];

        return ApiResponse::ok([
            'node_id'  => $info['node_id'] ?? config('Mailrunner')->nodeId,
            'hostname' => (string) parse_url((string) config('App')->baseURL, PHP_URL_HOST),
            'version'  => $info['version'],
            'roles'    => $info['roles'],
            'selftest' => $last === null ? null : ['recorded_at' => Time::toIso($last['created_at'])] + (json_decode((string) $last['details'], true) ?: []),
            'counts'   => $counts,
            'policy'   => ['updates' => config('Mailrunner')->adminUpdates, 'config' => config('Mailrunner')->adminConfig],
            'pending_changes' => (new PendingChanges())->count(),
            'update_requested' => Updater::forNode()->requested()['version'] ?? null,
            'secrets'  => \App\Core\Node\CommonSecrets::forNode()->summary() + ['pending_revision' => (new PendingChanges())->openPayload('secrets', 'common')['revision'] ?? null],
        ]);
    }

    /**
     * PUT /admin/v1/node/secrets {"revision": "xxxx-xxxx", "values": {"SPAMHAUS_DQS_KEY": "..."}} - replaces the common
     * secrets (full desired state). MAILRUNNER_ADMIN_CONFIG=manual: stored encrypted with the node key until
     * mr:config:apply. Values are never returned or journaled, only names.
     */
    public function putSecrets()
    {
        $body     = $this->body();
        $revision = (string) ($body['revision'] ?? '');
        $values   = is_array($body['values'] ?? null) ? $body['values'] : [];
        if (($err = \App\Core\Node\CommonSecrets::invalid($values, $revision)) !== null) {
            return ApiResponse::error(422, 'INVALID_SECRETS', $err);
        }
        if ($this->configManual()) {
            $enc = base64_encode(\App\Core\Secrets\NodeCrypto::forNode()->encrypt(json_encode($values), 'admin-secrets:' . $revision));

            return $this->queued('secrets', 'common', ['revision' => $revision, 'keys' => array_keys($values), 'enc' => $enc]);
        }
        (new \App\Core\Admin\AdminChanges())->secrets($revision, $values, $this->ctx());

        return ApiResponse::ok(['revision' => $revision, 'keys' => array_keys($values)]);
    }

    /**
     * PUT /admin/v1/node/update {"version": "n.n.n"} - asks the node to update. The node fetches the tag from its own
     * source repository; with MAILRUNNER_ADMIN_UPDATES=manual the request is only noted (mr:update on the node).
     */
    public function requestUpdate()
    {
        $body    = $this->request->getJSON(true) ?: [];
        $version = (string) ($body['version'] ?? '');
        if (! NodeInfo::isValidVersion($version)) {
            return ApiResponse::error(422, 'INVALID_VERSION', 'version must be n.n.n');
        }
        $current = (new NodeInfo(config('Mailrunner')))->version();
        if (version_compare($version, $current, '<=')) {
            return ApiResponse::error(422, 'NOT_NEWER', 'v' . $version . ' is not newer than the installed v' . $current);
        }
        Updater::forNode()->request($version, (string) ($this->ctx()->actor ?? 'admin'));
        $mode = config('Mailrunner')->adminUpdates;
        Transaction::run(db_connect(), fn () => service('journalWriter')->append('node.update_requested', $this->ctx(), null, null, ['version' => $version, 'mode' => $mode]));

        return ApiResponse::ok(['requested' => $version, 'current' => $current, 'mode' => $mode,
            'message' => $mode === 'auto' ? 'The node installs the update now.' : 'Noted; install on the node: sudo mailrunner mr:update'], 202);
    }

    public function journal()
    {
        $after = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit = max(1, min(1000, (int) ($this->request->getGet('limit') ?? 100)));
        $types = array_values(array_filter(array_map('trim', explode(',', (string) ($this->request->getGet('event') ?? '')))));
        $b     = db_connect()->table('journal j')
            ->select('j.id, j.created_at, j.event, j.source, j.actor, j.details, c.customer_key, p.project_key')
            ->join('customers c', 'c.id = j.customer_id', 'left')->join('projects p', 'p.id = j.project_id', 'left')
            ->limit($limit);
        $latest = $this->request->getGet('latest') === '1';
        $latest ? $b->orderBy('j.id', 'DESC') : $b->where('j.id >', $after)->orderBy('j.id');
        if ($types !== []) {
            $b->whereIn('j.event', $types);
        }
        $rows = array_map(static fn ($r) => [
            'id'         => (int) $r['id'],
            'created_at' => Time::toIso($r['created_at']),
            'event'      => $r['event'],
            'source'     => $r['source'],
            'actor'      => $r['actor'],
            'customer'   => $r['customer_key'],
            'project'    => $r['project_key'] === null ? null : $r['customer_key'] . '/' . $r['project_key'],
            'details'    => $r['details'] === null ? null : json_decode($r['details'], true),
        ], $b->get()->getResultArray());

        return ApiResponse::ok(['data' => $rows, 'next_after_id' => $rows === [] ? $after : end($rows)['id'], 'has_more' => count($rows) === $limit]);
    }
}
