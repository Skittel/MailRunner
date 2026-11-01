<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Every page except /auth/* needs a signed-in administrator (Entra app role). */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session('user_id') && session('is_admin')) {
            return null;
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
