<?php

namespace Tests\Unit\Core;

use App\Core\Node\Updater;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Updater against a temporary git repository with tags: plan (changelog, commits), downgrade and local-change
 * guards, run with a fake installer.
 *
 * @internal
 */
final class UpdaterTest extends CIUnitTestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/mr-updater-' . bin2hex(random_bytes(4));
        mkdir($this->repo);
        $this->git('init -q');
        file_put_contents($this->repo . '/CHANGELOG.md', "# Changelog\n\n## 0.1.0 - first\n- start\n");
        file_put_contents($this->repo . '/install.txt', "a\n");
        $this->git('add -A');
        $this->git('commit -q -m one');
        $this->git('tag v0.1.0');
        file_put_contents($this->repo . '/CHANGELOG.md', "# Changelog\n\n## 0.1.1 - second\n- new feature\n\n## 0.1.0 - first\n- start\n");
        file_put_contents($this->repo . '/install.txt', "b\n");
        $this->git('commit -q -am two');
        $this->git('tag v0.1.1');
        $this->git('checkout -q v0.1.0');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo));
        parent::tearDown();
    }

    private function git(string $args): void
    {
        exec('git -c user.name=t -c user.email=t@example.org -C ' . escapeshellarg($this->repo) . ' ' . $args . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    private function updater(string $current, array $installer = ['true']): Updater
    {
        return new Updater($this->repo, $current, $installer, $this->repo . '/desired.json');
    }

    public function testPlanShowsChangelogAndCommits(): void
    {
        $plan = $this->updater('0.1.0')->plan(null);
        $this->assertSame([], $plan['problems']);
        $this->assertSame('0.1.1', $plan['target']); // newest tag
        $this->assertCount(1, $plan['commits']);
        $this->assertStringContainsString('new feature', $plan['changelog']);
        $this->assertStringNotContainsString('start', $plan['changelog']);
        $this->assertStringContainsString('changed', $plan['files']);
    }

    public function testRequestedVersionIsTheDefaultTarget(): void
    {
        $u = $this->updater('0.1.0');
        $u->request('0.1.1', 'CN=admin');
        $this->assertSame('0.1.1', $u->requested()['version']);
        $this->assertSame('0.1.1', $u->plan(null)['target']);
    }

    public function testGuards(): void
    {
        $plan = $this->updater('0.1.1')->plan('0.1.0');
        $this->assertTrue($plan['downgrade']);
        $this->assertStringContainsString('allow-downgrade', implode(' ', $plan['problems']));
        $this->assertSame([], $this->updater('0.1.1')->plan('0.1.0', true)['problems']);
        $this->assertStringContainsString('not found', implode(' ', $this->updater('0.1.0')->plan('0.9.9')['problems']));
        $this->assertStringContainsString('already at', implode(' ', $this->updater('0.1.1')->plan('0.1.1')['problems']));
        file_put_contents($this->repo . '/install.txt', "local change\n");
        $this->assertStringContainsString('local changes', implode(' ', $this->updater('0.1.0')->plan('0.1.1')['problems']));
        $this->assertSame([], $this->updater('0.1.0')->plan('0.1.1', false, true)['problems']);
    }

    public function testRunChecksOutTheTagAndRunsTheInstaller(): void
    {
        $lines = [];
        $code  = $this->updater('0.1.0', ['sh', '-c', 'echo installing; cat install.txt'])->run('0.1.1', false, static function ($l) use (&$lines) { $lines[] = $l; });
        $this->assertSame(0, $code);
        $this->assertSame(['installing', 'b'], $lines);
    }
}
