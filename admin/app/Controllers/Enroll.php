<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\Clock;
use App\Libraries\Enrollment;
use App\Libraries\PublicId;
use App\Libraries\Sync;
use Throwable;

/**
 * Self-registration of a new node (install.sh --admin-url=... --enroll-token=...). No session: the one-time
 * enrollment token is the credential. GET /enroll/ca.pem returns the admin CA (public certificate) the node must
 * trust; POST /enroll registers the node and consumes the token.
 */
class Enroll extends BaseController
{
    public function ca()
    {
        if (Enrollment::find($this->request->getHeaderLine('Authorization')) === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'invalid or expired enrollment token']);
        }
        $ca = dirname(config('Admin')->mtlsCert) . '/ca.pem';

        return $this->response->setHeader('Content-Type', 'application/x-pem-file')->setBody((string) @file_get_contents($ca));
    }

    public function register()
    {
        $e = Enrollment::find($this->request->getHeaderLine('Authorization'));
        if ($e === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'invalid or expired enrollment token']);
        }
        $body    = json_decode((string) $this->request->getBody(), true) ?: [];
        $nodeKey = (string) ($body['node_id'] ?? '');
        $baseUrl = rtrim((string) ($body['base_url'] ?? ''), '/');
        if ($nodeKey !== $e['node_key'] || $baseUrl !== 'https://' . $e['hostname']) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'node_id/base_url do not match the enrollment ('
                . $e['node_key'] . ', https://' . $e['hostname'] . ')']);
        }
        $db = db_connect();
        if ($db->table('nodes')->where('node_key', $nodeKey)->countAllResults() > 0) {
            return $this->response->setStatusCode(409)->setJSON(['error' => 'node ' . $nodeKey . ' already exists']);
        }
        $now = Clock::now();
        $db->table('nodes')->insert(['public_id' => PublicId::generate('nodes'), 'node_key' => $nodeKey, 'name' => $nodeKey,
            'base_url' => $baseUrl, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $nodeId = (int) $db->insertID();
        $db->table('node_enrollments')->where('id', $e['id'])->update(['used_at' => $now, 'node_id' => $nodeId]);
        Audit::log('node.enrolled', $nodeKey, ['base_url' => $baseUrl, 'version' => (string) ($body['version'] ?? ''), 'enrollment' => (int) $e['id']]);
        try {
            (new Sync(config('Admin')))->poll($db->table('nodes')->where('id', $nodeId)->get()->getRowArray());
        } catch (Throwable) {
            // shown on the node card; the poll timer retries every minute
        }

        return $this->response->setStatusCode(201)->setJSON(['node_id' => $nodeKey, 'registered' => true]);
    }
}
