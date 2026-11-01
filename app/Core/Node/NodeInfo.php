<?php

namespace App\Core\Node;

use Config\Mailrunner;
use RuntimeException;

/**
 * Read-only facts about this node: identity, software version, roles.
 */
class NodeInfo
{
    public function __construct(
        private readonly Mailrunner $config,
        private readonly string $versionFile = ROOTPATH . 'VERSION',
    ) {
    }

    /**
     * Software version from the VERSION file (n.n.n, each part 0-999).
     */
    public function version(): string
    {
        if (! is_file($this->versionFile)) {
            throw new RuntimeException('VERSION file missing: ' . $this->versionFile);
        }

        $version = trim((string) file_get_contents($this->versionFile));
        if (! self::isValidVersion($version)) {
            throw new RuntimeException('Invalid version string: ' . $version);
        }

        return $version;
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})$/', $version) === 1;
    }

    /**
     * @return array{node_id: string, version: string, roles: list<string>}
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->config->nodeId,
            'version' => $this->version(),
            'roles'   => $this->config->roles(),
        ];
    }
}
