<?php

namespace App\Core\Auth;

use App\Core\Api\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin API authentication (decision E-19): nginx verifies the client certificate against the
 * MailRunner Admin CA (/etc/mailrunner/admin-ca.pem) and passes the result as MR_CLIENT_VERIFY /
 * MR_CLIENT_S_DN. Second line of defence behind nginx's own 403 for /admin/.
 */
class AdminCertFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        CurrentAdmin::set(null);
        $verify = (string) ($request->getServer('MR_CLIENT_VERIFY') ?? '');
        $dn     = (string) ($request->getServer('MR_CLIENT_S_DN') ?? '');
        if ($verify !== 'SUCCESS' || $dn === '') {
            return ApiResponse::error(403, 'ADMIN_CERT_REQUIRED', 'A client certificate of MailRunner Admin is required');
        }
        CurrentAdmin::set($dn);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
