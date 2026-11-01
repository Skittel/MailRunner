<?php

namespace App\Commands;

use App\Core\Support\Duration;

class TokenRotate extends MrCommand
{
    protected $name        = 'mr:token:rotate';
    protected $description = 'Rotates an API token: new token, old one stays valid for the grace period.';
    protected $usage       = 'mr:token:rotate <token_id> [--grace=24h] [--force] [--json]';
    protected $options     = ['--grace' => 'How long the old token stays valid (default 24h, 0 = revoke now)', '--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $grace  = Duration::toSeconds($this->optionString('grace') ?? '24h');
        $result = service('apiTokenService')->rotate($this->requireArg(0, 'token_id'), $grace, $this->context());
        if ($this->json()) {
            $this->outputJson(['token_id' => $result['token']['token_id'], 'secret' => $result['secret'], 'bearer' => $result['bearer'],
                'old_token_id' => $result['old']['token_id'], 'old_expires_at' => $result['old']['expires_at'], 'old_revoked_at' => $result['old']['revoked_at']]);

            return null;
        }
        $this->outputSecret('New API token', [
            'ID'     => $result['token']['token_id'],
            'Secret' => $result['secret'],
            'Header' => 'Authorization: Bearer ' . $result['bearer'],
        ]);
        $this->ok('Old token ' . $result['old']['token_id'] . ($grace === 0 ? ' revoked now.' : ' valid until ' . substr($result['old']['expires_at'], 0, 19) . ' UTC.'));

        return null;
    }
}
