<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Every page except /auth/* needs a signed-in administrator; changes (non-GET) need the RW group. */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session('user_id') && session('is_admin')) {
            if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true) || can_write()) {
                return null;
            }

            return service('response')->setStatusCode(403)->setBody('Nur Lesezugriff: Änderungen erfordern die Schreibgruppe.');
        }
        if ($request->hasHeader('HX-Request')) {
            return service('response')->setStatusCode(401)->setHeader('HX-Redirect', site_url('auth/login'));
        }
        session()->set('after_login', current_url());

        return redirect()->to(site_url('auth/login'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
