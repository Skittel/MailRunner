<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * MailRunner node configuration.
 *
 * Node-specific values come from the environment (.env or /etc/mailrunner/*.env),
 * never from this file. Defaults here are safe for development only.
 */
class Mailrunner extends BaseConfig
{
    /** Enables the send role (outbound mail). */
    public bool $sendEnabled = false;

    /** Enables the receive role (inbound mail). */
    public bool $receiveEnabled = false;

    /** Immutable node identity, e.g. "node1.eu". Set once at installation. */
    public string $nodeId = '';

    /** Directory holding node configuration and keys. */
    public string $configDir = '/etc/mailrunner';

    /** Directory holding spool, store and other runtime data. */
    public string $dataDir = '/var/lib/mailrunner';

    /**
     * MX names of this node with the expected address families, checked by the self test,
     * e.g. "mx.example.net=46 mx4.example.net=4 mx6.example.net=6". Empty: the node name only.
     */
    public string $mxNames = '';

    /**
     * Send role (E-27): HELO name of outgoing connections and the source addresses the delivery worker uses
     * (space-separated). The self test checks that the addresses belong to the node, their PTR names the HELO name,
     * the HELO name resolves to them and outgoing port 25 is open.
     */
    public string $sendHelo = '';

    /** @var list<string> */
    public array $sendIpv4 = [];

    /** @var list<string> */
    public array $sendIpv6 = [];

    /**
     * What MailRunner Admin may do on this node (app.env), each "auto" (default) or "manual":
     *   adminUpdates  auto: a software update requested by the admin is installed at once (mailrunner-update unit);
     *                 manual: it is only noted, install with "sudo mailrunner mr:update".
     *   adminConfig   auto: configuration from the admin is applied at once;
     *                 manual: it waits on the node, apply with "sudo mailrunner mr:config:apply".
     * The code itself always comes from the git tags of the source repository, never from the admin.
     */
    public string $adminUpdates = 'auto';
    public string $adminConfig  = 'auto';

    /** Git checkout the node is installed from (install.sh writes it); mr:update works there. */
    public string $sourceDir = '/opt/src/MailRunner';

    /**
     * Credentials MailRunner Admin may distribute to all nodes (common secrets, admin settings). They are written to
     * commonSecretsFile(); a non-empty value of the same name in secrets.env (local) wins.
     */
    public const COMMON_SECRET_KEYS = ['SPAMHAUS_DQS_KEY', 'BLM_USER', 'BLM_PASS', 'IPREGISTRYIO_TOKEN'];

    /** Days the journal keeps delivery attempts (smtp.connection / smtp.attempt). */
    public int $smtpJournalDays = 30;

    public function __construct()
    {
        parent::__construct();

        // CI4 maps env keys "mailrunner.<property>"; also accept the documented flat names.
        $this->sendEnabled    = $this->envBool('MAILRUNNER_SEND_ENABLED', $this->sendEnabled);
        $this->receiveEnabled = $this->envBool('MAILRUNNER_RECEIVE_ENABLED', $this->receiveEnabled);
        $this->nodeId         = (string) (env('MAILRUNNER_NODE_ID') ?? $this->nodeId);
        $this->configDir      = rtrim((string) (env('MAILRUNNER_CONFIG_DIR') ?? $this->configDir), '/');
        $this->dataDir        = rtrim((string) (env('MAILRUNNER_DATA_DIR') ?? $this->dataDir), '/');
        $this->smtpJournalDays = (int) (env('MAILRUNNER_SMTP_JOURNAL_DAYS') ?? $this->smtpJournalDays);
        $this->mxNames         = trim((string) (env('MAILRUNNER_MX_NAMES') ?? $this->mxNames));
        $this->sendHelo        = strtolower(trim((string) (env('MAILRUNNER_SEND_HELO') ?? $this->sendHelo)));
        $this->sendIpv4        = self::ipList(env('MAILRUNNER_SEND_IPV4'), FILTER_FLAG_IPV4) ?? $this->sendIpv4;
        $this->sendIpv6        = self::ipList(env('MAILRUNNER_SEND_IPV6'), FILTER_FLAG_IPV6) ?? $this->sendIpv6;
        $this->adminUpdates    = env('MAILRUNNER_ADMIN_UPDATES') === 'manual' ? 'manual' : 'auto';
        $this->adminConfig     = env('MAILRUNNER_ADMIN_CONFIG') === 'manual' ? 'manual' : 'auto';
        $this->sourceDir       = rtrim((string) (env('MAILRUNNER_SOURCE_DIR') ?? $this->sourceDir), '/');
    }

    public function nodeKeyFile(): string
    {
        return $this->configDir . '/node.key';
    }

    public function generatedDir(): string
    {
        return $this->configDir . '/generated';
    }

    public function receiveSpoolDir(): string
    {
        return $this->dataDir . '/receive/spool';
    }

    /** Desired software version from MailRunner Admin (written by the node admin API, read by mr:update). */
    public function updateRequestFile(): string
    {
        return $this->dataDir . '/update/desired.json';
    }

    /** Common secrets from MailRunner Admin (written by the node API as user mailrunner, 0600). */
    public function commonSecretsFile(): string
    {
        return $this->dataDir . '/secrets/common.env';
    }

    public function receiveStoreDir(): string
    {
        return $this->dataDir . '/receive/store';
    }

    /**
     * Returns the enabled roles as a list, e.g. ["receive", "send"].
     *
     * @return list<string>
     */
    public function roles(): array
    {
        $roles = [];
        if ($this->receiveEnabled) {
            $roles[] = 'receive';
        }
        if ($this->sendEnabled) {
            $roles[] = 'send';
        }

        return $roles;
    }

    /** @return list<string>|null null = not set */
    private static function ipList(mixed $value, int $flag): ?array
    {
        if ($value === null || $value === false) {
            return null;
        }

        return array_values(array_filter(preg_split('/[\s,]+/', (string) $value) ?: [], static fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, $flag) !== false));
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
