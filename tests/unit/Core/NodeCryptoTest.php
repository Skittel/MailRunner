<?php

namespace Tests\Unit\Core;

use App\Core\Secrets\NodeCrypto;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class NodeCryptoTest extends CIUnitTestCase
{
    public function testSharedVectorsMatchPython(): void
    {
        $v      = json_decode((string) file_get_contents(ROOTPATH . 'tests/shared/crypto_vectors.json'), true);
        $crypto = NodeCrypto::fromKey(base64_decode($v['key_b64'], true));
        foreach ($v['cases'] as $case) {
            $plain = base64_decode($case['plaintext_b64'], true);
            $blob  = base64_decode($case['blob_b64'], true);
            $this->assertSame($blob, $crypto->encrypt($plain, $case['context'], base64_decode($v['nonce_b64'], true)));
            $this->assertSame($plain, $crypto->decrypt($blob, $case['context']));
        }
    }

    public function testWrongContextFails(): void
    {
        $crypto = NodeCrypto::fromKey(random_bytes(32));
        $blob   = $crypto->encryptJson(['a' => 1], 'db:x:1');
        $this->assertSame(['a' => 1], $crypto->decryptJson($blob, 'db:x:1'));

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($blob, 'db:x:2');
    }
}
