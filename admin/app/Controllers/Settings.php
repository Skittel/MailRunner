<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\CommonSecrets;
use App\Libraries\Sync;

/** Einstellungen: common secrets for all nodes (write access only; the filter blocks POST for read-only users). */
class Settings extends BaseController
{
    public function index()
    {
        $store = CommonSecrets::make();

        return view('settings/index', [
            'keys'     => CommonSecrets::KEYS,
            'secrets'  => $store->overview(),
            'revision' => $store->revision(),
            'nodes'    => db_connect()->table('nodes')->where('enabled', 1)->orderBy('node_key')->get()->getResultArray(),
        ]);
    }

    public function saveSecrets()
    {
        $values = (array) ($this->request->getPost('value') ?? []);
        $delete = array_keys(array_filter((array) ($this->request->getPost('delete') ?? [])));
        $set    = [];
        foreach ($values as $k => $v) {
            $v = trim((string) $v);
            if ($v === '' || ! isset(CommonSecrets::KEYS[$k]) || in_array($k, $delete, true)) {
                continue; // empty field: unchanged
            }
            if (! CommonSecrets::validValue($v)) {
                return redirect()->to(site_url('settings'))->with('error', CommonSecrets::KEYS[$k][0] . ': ungültiger Wert (max. 512 Zeichen, keine Steuerzeichen)');
            }
            $set[$k] = $v;
        }
        $changed = CommonSecrets::make()->save($set, $delete, session('user_id'));
        if ($changed === []) {
            return redirect()->to(site_url('settings'))->with('ok', 'Keine Änderung.');
        }
        Audit::log('secrets.saved', null, ['changed' => $changed, 'deleted' => array_values(array_intersect($delete, $changed))]);
        $sync = new Sync(config('Admin'));
        foreach (db_connect()->table('nodes')->where('enabled', 1)->get()->getResultArray() as $node) {
            $sync->poll($node); // pushes the new revision and stores the node's answer
        }

        return redirect()->to(site_url('settings'))->with('ok', 'Gespeichert (' . implode(', ', $changed) . ') und an die Nodes verteilt.');
    }
}
