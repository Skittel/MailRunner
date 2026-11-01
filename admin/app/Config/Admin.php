<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * MailRunner Admin configuration. Values come from /etc/mailrunner-admin/admin.env (linked as .env).
 */
class Admin extends BaseConfig
{
    /** Entra ID (OpenID Connect): directory (tenant) id, application (client) id and client secret. */
    public string $entraTenantId = '';
    public string $entraClientId = '';
    public string $entraClientSecret = '';

    /** App role (defined in the app registration, assigned in the enterprise application) for administrators. */
    public string $adminRole = 'MailRunner.Admin';

    /** mTLS client certificate and key for the node admin APIs (signed by the MailRunner Admin CA). */
    public string $mtlsCert = '/etc/mailrunner-admin/mtls/client.pem';
    public string $mtlsKey = '/etc/mailrunner-admin/mtls/client.key';

    /** Timeout for node admin API calls (seconds). */
    public int $nodeTimeout = 15;

    public function __construct()
    {
        parent::__construct();
        $this->entraTenantId     = (string) (env('ENTRA_TENANT_ID') ?? $this->entraTenantId);
        $this->entraClientId     = (string) (env('ENTRA_CLIENT_ID') ?? $this->entraClientId);
        $this->entraClientSecret = (string) (env('ENTRA_CLIENT_SECRET') ?? $this->entraClientSecret);
        $this->adminRole         = (string) (env('ADMIN_ROLE') ?? $this->adminRole);
        $this->mtlsCert          = (string) (env('MTLS_CERT') ?? $this->mtlsCert);
        $this->mtlsKey           = (string) (env('MTLS_KEY') ?? $this->mtlsKey);
    }
}
