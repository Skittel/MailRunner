<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\Clock;
use App\Libraries\EntraAuth;
use Throwable;

/** Sign-in with Entra ID; access comes from Entra group membership (Graph check at sign-in) (Config\Admin::$groupsRw / $groupsRo). */
class Auth extends BaseController
{
    private function entra(): EntraAuth
    {
        return new EntraAuth(config('Admin'), service('curlrequest'));
    }

    private function redirectUri(): string
    {
        return site_url('auth/callback');
    }

    public function login()
    {
        $entra = $this->entra();
        if (! $entra->configured()) {
            return view('auth/message', ['title' => 'Anmeldung nicht eingerichtet', 'text' => 'Entra ID ist noch nicht konfiguriert (ENTRA_TENANT_ID, ENTRA_CLIENT_ID, ENTRA_CLIENT_SECRET in /etc/mailrunner-admin/admin.env).']);
        }
        if ($this->request->getGet('go') === null) {
            return view('auth/login');
        }
        $start = $entra->start($this->redirectUri());
        session()->set('oidc', ['state' => $start['state'], 'nonce' => $start['nonce'], 'verifier' => $start['verifier']]);

        return redirect()->to($start['url']);
    }

    public function callback()
    {
        $oidc = session('oidc');
        session()->remove('oidc');
        $state = (string) $this->request->getGet('state');
        if (! is_array($oidc) || ! hash_equals($oidc['state'], $state)) {
            return view('auth/message', ['title' => 'Anmeldung abgebrochen', 'text' => 'Ungültiger Anmeldestatus. Bitte erneut anmelden.']);
        }
        if ($this->request->getGet('error')) {
            return view('auth/message', ['title' => 'Anmeldung abgelehnt', 'text' => (string) $this->request->getGet('error_description')]);
        }

        try {
            $entra  = $this->entra();
            $result = $entra->finish((string) $this->request->getGet('code'), $this->redirectUri(), $oidc['verifier'], $oidc['nonce']);
            $claims = $result['claims'];
            $cfg    = config('Admin');
            $access = $cfg->accessFor($entra->memberGroups($result['access_token'], [...$cfg->groupsRw, ...$cfg->groupsRo]));
        } catch (Throwable $e) {
            log_message('error', 'Entra sign-in failed: {msg}', ['msg' => $e->getMessage()]);

            return view('auth/message', ['title' => 'Anmeldung fehlgeschlagen', 'text' => 'Anmeldung oder Gruppenprüfung bei Microsoft ist fehlgeschlagen.']);
        }

        $db      = db_connect();
        $now     = Clock::now();
        $row     = ['name' => (string) ($claims['name'] ?? $claims['preferred_username'] ?? 'unknown'),
            'email' => (string) ($claims['email'] ?? $claims['preferred_username'] ?? ''), 'roles' => json_encode($access === null ? [] : [$access]), 'last_login_at' => $now];
        $user = $db->table('users')->where('tenant_id', $claims['tid'])->where('entra_oid', $claims['oid'])->get()->getRowArray();
        if ($user === null) {
            $db->table('users')->insert($row + ['tenant_id' => $claims['tid'], 'entra_oid' => $claims['oid'], 'created_at' => $now]);
            $userId = (int) $db->insertID();
        } else {
            $db->table('users')->where('id', $user['id'])->update($row);
            $userId = (int) $user['id'];
        }
        if ($access === null) {
            Audit::log('auth.denied', $row['email'], ['reason' => 'no admin group']);

            return view('auth/message', ['title' => 'Kein Zugriff', 'text' => 'Ihr Konto ist in keiner der MailRunner-Admin-Gruppen (Lesen oder Schreiben).']);
        }
        session()->regenerate(true);
        session()->set(['user_id' => $userId, 'user_name' => $row['name'], 'user_email' => $row['email'], 'is_admin' => true, 'access' => $access]);
        Audit::log('auth.login', $row['email'], ['access' => $access]);
        $to = session('after_login') ?: site_url('/');
        session()->remove('after_login');

        return redirect()->to($to);
    }

    public function logout()
    {
        Audit::log('auth.logout', session('user_email'));
        session()->destroy();

        return view('auth/message', ['title' => 'Abgemeldet', 'text' => 'Sie wurden abgemeldet.', 'login' => true]);
    }
}
