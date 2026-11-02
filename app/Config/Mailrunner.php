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

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
