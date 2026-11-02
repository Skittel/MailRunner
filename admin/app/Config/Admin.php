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

    /**
     * Entra security groups (object ids, comma-separated) that may sign in. Members of a RW group may change
     * configuration; members of a RO group only read (including postmaster/abuse mails). RW wins if a user is in both.
     * The app registration must emit the groups claim (Token configuration -> Add groups claim -> Security groups).
     *
     * @var list<string>
     */
    public array $groupsRw = [];

    /** @var list<string> */
    public array $groupsRo = [];

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
        $this->groupsRw          = self::idList(env('ENTRA_GROUP_RW')) ?: $this->groupsRw;
        $this->groupsRo          = self::idList(env('ENTRA_GROUP_RO')) ?: $this->groupsRo;
        $this->mtlsCert          = (string) (env('MTLS_CERT') ?? $this->mtlsCert);
        $this->mtlsKey           = (string) (env('MTLS_KEY') ?? $this->mtlsKey);
    }

    /** @return list<string> */
    private static function idList(mixed $value): array
    {
        return array_values(array_filter(array_map(static fn ($v) => strtolower(trim($v)), explode(',', (string) $value))));
    }

    /**
     * Access level for the given group ids: 'rw', 'ro' or null (no access).
     *
     * @param list<string> $groups
     */
    public function accessFor(array $groups): ?string
    {
        $groups = array_map('strtolower', $groups);

        return match (true) {
            array_intersect($this->groupsRw, $groups) !== [] => 'rw',
            array_intersect($this->groupsRo, $groups) !== [] => 'ro',
            default                                          => null,
        };
    }
}
