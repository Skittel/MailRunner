<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Secrets\NodeCrypto;
use RuntimeException;

/**
 * Read/delete access to the encrypted message files written by mr-milter
 * (store/YYYY/MM/DD/<id>.analysis.json.enc and <id>.eml.enc).
 */
class MessageStore
{
    public function __construct(private readonly string $storeDir, private readonly NodeCrypto $crypto)
    {
    }

    public static function forNode(): self
    {
        return new self(config('Mailrunner')->receiveStoreDir(), NodeCrypto::forNode());
    }

    /**
     * @return array<string, mixed>
     */
    public function analysis(string $spoolId, string $relativePath): array
    {
        return json_decode($this->crypto->decrypt($this->read($relativePath), 'store:' . $spoolId . ':analysis'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function raw(string $spoolId, string $relativePath): string
    {
        return $this->crypto->decrypt($this->read($relativePath), 'store:' . $spoolId . ':eml');
    }

    public function delete(?string $relativePath): void
    {
        if ($relativePath === null) {
            return;
        }
        $path = $this->path($relativePath);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function read(string $relativePath): string
    {
        $data = @file_get_contents($this->path($relativePath));
        if ($data === false) {
            throw new RuntimeException('Message file missing: ' . $relativePath);
        }

        return $data;
    }

    private function path(string $relativePath): string
    {
        if (preg_match('#^\d{4}/\d{2}/\d{2}/[0-9TZ]{16}-[0-9a-f]{16}\.(analysis\.json|eml)\.enc$#', $relativePath) !== 1) {
            throw new RuntimeException('Invalid message file path');
        }

        return $this->storeDir . '/' . $relativePath;
    }
}
