<?php

namespace Tests\Unit\Receive;

use App\Modules\Receive\Libraries\ConfigBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ConfigBuilderTest extends CIUnitTestCase
{
    public function testRecipientRuleAnchorsPatternAndAllowsExceptions(): void
    {
        $rule = ConfigBuilder::recipientRule('test.smartmailflow.email', '^[A-Za-z0-9_-]{8,}$', ['postmaster', 'abuse']);

        $this->assertSame(
            "if /@test\\.smartmailflow\\.email$/\n"
            . "/^(?:postmaster|abuse)@/ DUNNO\n"
            . "!/^(?:[A-Za-z0-9_-]{8,})@/ 550 5.1.1 User unknown\n"
            . 'endif',
            $rule,
        );
    }

    public function testSlashInPatternIsEscaped(): void
    {
        $this->assertStringContainsString('!/^(?:a\/b)@/', ConfigBuilder::recipientRule('example.org', 'a/b', []));
    }
}
