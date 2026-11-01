<?php

namespace Tests\Unit\Core;

use App\Core\Node\NodeInfo;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Mailrunner;

/**
 * @internal
 */
final class NodeInfoTest extends CIUnitTestCase
{
    public function testRepositoryVersionFileIsValid(): void
    {
        $info = new NodeInfo(new Mailrunner());

        $this->assertTrue(NodeInfo::isValidVersion($info->version()));
    }

    /**
     * @dataProvider versionProvider
     */
    public function testVersionValidation(string $version, bool $valid): void
    {
        $this->assertSame($valid, NodeInfo::isValidVersion($version));
    }

    public static function versionProvider(): iterable
    {
        yield ['0.0.1', true];
        yield ['999.999.999', true];
        yield ['1.10.0', true];
        yield ['1000.0.0', false];
        yield ['01.0.0', false];
        yield ['1.0', false];
        yield ['v1.0.0', false];
    }

    public function testRolesFollowConfiguration(): void
    {
        $config                 = new Mailrunner();
        $config->receiveEnabled = true;
        $config->sendEnabled    = true;

        $this->assertSame(['receive', 'send'], $config->roles());
    }
}
