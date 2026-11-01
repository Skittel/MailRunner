<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\MailView;
use App\Libraries\Sync;
use Throwable;

/** postmaster@/abuse@ mails of all nodes (E-18/E-19), read live from the node admin APIs. */
class RoleMails extends BaseController
{
    private function sync(): Sync
    {
        return new Sync(config('Admin'));
    }

    private function node(int $id): array
    {
        return db_connect()->table('nodes')->where('id', $id)->get()->getRowArray() ?? throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    public function index()
    {
        return view('rolemails/index', ['nodes' => db_connect()->table('nodes')->where('enabled', 1)->orderBy('node_key')->get()->getResultArray()]);
    }

    /** htmx partial: list of one node. */
    public function list(int $nodeId)
    {
        $node = $this->node($nodeId);
        try {
            $data = $this->sync()->client($node)->ok('GET', 'role-mails?limit=100')['data'];
        } catch (Throwable $e) {
            return view('rolemails/_list', ['node' => $node, 'mails' => [], 'error' => $e->getMessage()]);
        }

        return view('rolemails/_list', ['node' => $node, 'mails' => $data, 'error' => null]);
    }

    public function show(int $nodeId, string $messageId)
    {
        $node = $this->node($nodeId);
        try {
            $client = $this->sync()->client($node);
            $msg    = $client->ok('GET', 'messages/' . rawurlencode($messageId))['data'];
            $view   = $msg['raw_available'] ? MailView::parse($client->raw('messages/' . rawurlencode($messageId) . '/raw')) : null;
        } catch (Throwable $e) {
            return redirect()->to(site_url('role-mails'))->with('error', 'Mail nicht abrufbar: ' . $e->getMessage());
        }
        Audit::log('rolemail.read', $node['node_key'] . ' ' . $messageId, ['project' => $msg['project'], 'role' => $msg['role']]);

        return view('rolemails/show', ['node' => $node, 'msg' => $msg, 'mail' => $view]);
    }

    public function raw(int $nodeId, string $messageId)
    {
        $node = $this->node($nodeId);
        $raw  = $this->sync()->client($node)->raw('messages/' . rawurlencode($messageId) . '/raw');
        Audit::log('rolemail.download', $node['node_key'] . ' ' . $messageId);

        return $this->response->setHeader('Content-Type', 'message/rfc822')->setHeader('Cache-Control', 'no-store')
            ->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^a-z0-9_]/i', '', $messageId) . '.eml"')->setBody($raw);
    }
}
