<?php

namespace App\Core\Auth;

use App\Core\Api\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Customer API authentication: "Authorization: Bearer <token_id>.<secret>".
 * A token only sees the data of its own project.
 */
class BearerTokenFilter implements FilterInterface
{
    /** Endpoints a send token may call besides v1/send/... (submission, step 3). */
    private const SEND_SCOPE_PATHS = ['v1/node/status', 'v1/send'];

    public function before(RequestInterface $request, $arguments = null)
    {
        CurrentToken::set(null);
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) !== 1) {
            return ApiResponse::error(401, 'UNAUTHORIZED', 'Missing bearer token')->setHeader('WWW-Authenticate', 'Bearer');
        }
        $token = service('apiTokenService')->authenticate($m[1]);
        if ($token === null) {
            return ApiResponse::error(401, 'UNAUTHORIZED', 'Invalid, expired or revoked token')->setHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        $project = db_connect()->table('projects p')->select('p.enabled, c.enabled AS customer_enabled')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $token['project_id'])->get()->getRowArray();
        if ($project === null || ! (bool) $project['enabled'] || ! (bool) $project['customer_enabled']) {
            return ApiResponse::error(403, 'PROJECT_DISABLED', 'Project is disabled');
        }
        if (($token['scope'] ?? 'manage') === 'send') {
            // a send token (access of a send profile, E-27) only submits mail; it cannot manage the project
            $path = trim($request instanceof \CodeIgniter\HTTP\IncomingRequest ? $request->getPath() : $request->getUri()->getPath(), '/');
            if (! in_array($path, self::SEND_SCOPE_PATHS, true) && ! str_starts_with($path, 'v1/send/')) {
                return ApiResponse::error(403, 'TOKEN_SCOPE', 'This token can only submit mail (send access); use a project token for this endpoint');
            }
            $access = service('sendAccessService')->forToken((int) $token['id']);
            if ($access === null || ! (bool) $access['enabled'] || ! (bool) $access['profile_enabled']) {
                return ApiResponse::error(403, 'ACCESS_DISABLED', 'The send access or its profile is disabled');
            }
            $token['send_access'] = $access;
        }
        CurrentToken::set($token);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
