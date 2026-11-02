<?php

namespace Tests\Unit;

use App\Libraries\EntraAuth;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Admin;
use RuntimeException;

/**
 * Group check via Microsoft Graph (POST /me/checkMemberGroups) with a fake HTTP client.
 *
 * @internal
 */
final class EntraAuthTest extends CIUnitTestCase
{
    /** @return array{0: EntraAuth, 1: object} */
    private function entra(int $status, string $body): array
    {
        $http = new class ($status, $body) extends CURLRequest {
            public array $calls = [];

            public function __construct(private int $fakeStatus, private string $fakeBody)
            {
            }

            public function post(string $url, array $options = []): Response
            {
                $this->calls[] = ['url' => $url, 'options' => $options];

                return (new Response(config('App')))->setStatusCode($this->fakeStatus)->setBody($this->fakeBody);
            }
        };

        return [new EntraAuth(new Admin(), $http), $http];
    }

    public function testReturnsMatchedGroups(): void
    {
        [$entra, $http] = $this->entra(200, '{"value":["bbbbbbbb-0000-0000-0000-000000000002"]}');
        $found = $entra->memberGroups('token', ['aaaaaaaa-0000-0000-0000-000000000001', 'bbbbbbbb-0000-0000-0000-000000000002']);
        $this->assertSame(['bbbbbbbb-0000-0000-0000-000000000002'], $found);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/checkMemberGroups', $http->calls[0]['url']);
        $this->assertSame('Bearer token', $http->calls[0]['options']['headers']['Authorization']);
        $this->assertCount(2, json_decode($http->calls[0]['options']['body'], true)['groupIds']);
    }

    public function testGraphErrorFailsClosed(): void
    {
        [$entra] = $this->entra(403, '{"error":{"code":"Authorization_RequestDenied"}}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization_RequestDenied');
        $entra->memberGroups('token', ['aaaaaaaa-0000-0000-0000-000000000001']);
    }

    public function testNoGroupsConfiguredMeansNoCall(): void
    {
        [$entra, $http] = $this->entra(500, '');
        $this->assertSame([], $entra->memberGroups('', []));
        $this->assertSame([], $http->calls);
    }
}
