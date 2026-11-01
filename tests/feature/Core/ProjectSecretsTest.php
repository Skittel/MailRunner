<?php

namespace Tests\Feature\Core;

use App\Core\Projects\ProjectSecretService;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Modules\Receive\Libraries\ConfigApplier;
use App\Modules\Receive\Libraries\ConfigBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

/**
 * @internal
 */
final class ProjectSecretsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private ChangeContext $cli;
    private NodeCrypto $crypto;
    private ProjectSecretService $secrets;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $this->cli     = new ChangeContext('cli', 'phpunit');
        $this->crypto  = NodeCrypto::fromKey(random_bytes(32));
        $this->secrets = new ProjectSecretService(db_connect(), service('journalWriter'), service('projectService'), $this->crypto);
        service('customerService')->create('smartmailflow', '', $this->cli);
        service('projectService')->create('smartmailflow/test', 'analysis', '', $this->cli);
        service('routeService')->set('smartmailflow/test', ['test.smartmailflow.email'], $this->cli);
    }

    public function testSecretsAreEncryptedListedWithoutValuesAndJournaledWithoutValues(): void
    {
        $this->secrets->set('smartmailflow/test', 'spamhaus_dqs_key', 'abcdefghij1234567890abcdef', $this->cli);
        $this->secrets->set('smartmailflow/test', 'spamhaus_dqs_key', 'zzzzzzzzzz1234567890abcdef', $this->cli); // update

        $row = db_connect()->table('project_secrets')->get()->getRowArray();
        $this->assertStringNotContainsString('zzzz', $row['value_enc']);
        $this->assertSame(['name', 'updated_at', 'updated_source'], array_keys($this->secrets->list('smartmailflow/test')[0]));
        $this->assertSame(['spamhaus_dqs_key' => 'zzzzzzzzzz1234567890abcdef'], $this->secrets->valuesForProject((int) $row['project_id']));

        $journal = implode(' ', array_column(db_connect()->table('journal')->get()->getResultArray(), 'details'));
        $this->assertStringNotContainsString('zzzz', $journal);
        $this->assertStringContainsString('spamhaus_dqs_key', $journal);

        try {
            $this->secrets->set('smartmailflow/test', 'nope', 'x', $this->cli);
            $this->fail('unknown name accepted');
        } catch (ConfigException $e) {
            $this->assertSame('UNKNOWN_SECRET', $e->errorCode);
        }
        try {
            $this->secrets->set('smartmailflow/test', 'spamhaus_dqs_key', 'has space', $this->cli);
            $this->fail('invalid value accepted');
        } catch (ConfigException $e) {
            $this->assertSame('INVALID_SECRET', $e->errorCode);
        }
    }

    public function testApplyWritesAnalysisSettingsEncrypted(): void
    {
        $this->secrets->set('smartmailflow/test', 'spamhaus_dqs_key', 'abcdefghij1234567890abcdef', $this->cli);
        $this->secrets->set('smartmailflow/test', 'blacklistmaster_user', 'bmuser', $this->cli);
        $this->secrets->set('smartmailflow/test', 'blacklistmaster_pass', 'bm pass!', $this->cli);

        $files    = (new ConfigBuilder(db_connect(), $this->secrets))->build();
        $settings = json_decode($files['analysis.json'], true)['projects'];
        $this->assertSame(['spamhaus_dqs_key' => 'abcdefghij1234567890abcdef', 'blacklistmaster' => ['user' => 'bmuser', 'pass' => 'bm pass!']], reset($settings));

        $dir = sys_get_temp_dir() . '/mr-apply-' . bin2hex(random_bytes(4));
        (new ConfigApplier($dir, $this->crypto, ConfigBuilder::ENCRYPTED_FILES))->apply($files, false);
        $this->assertFileDoesNotExist($dir . '/current/analysis.json');
        $blob = (string) file_get_contents($dir . '/current/analysis.json.enc');
        $this->assertStringNotContainsString('bmuser', $blob);
        $this->assertSame($files['analysis.json'], $this->crypto->decrypt($blob, 'generated:analysis.json'));
        $this->assertFileExists($dir . '/current/routes.json');
    }
}
