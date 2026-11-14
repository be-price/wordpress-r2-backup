<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DirectR2Repository, Restorer, V4Format};

final class LiveIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;
    private ?string $liveWorkspace = null;
    protected function tearDown(): void { if ($this->wordpress) { $this->wordpress->stop(); } if ($this->liveWorkspace !== null && is_dir($this->liveWorkspace)) { $this->removeTree($this->liveWorkspace); } if ($this->database) { $this->database->stop(); } Fixture::cleanup(); }

    public function testActualWordPressConfigurationAndLiveCaptureRestoreWithBothKeys(): void
    {
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $page = '/wp-admin/tools.php?page=wrb-synthetic-backup';
        self::assertSame(302, $this->wordpress->request($page, null, 'guest')['status']);
        self::assertSame(403, $this->wordpress->request($page, null, 'reader')['status']);
        $html = $this->wordpress->request($page)['body'];
        self::assertStringContainsString('バックアップ', $html);
        self::assertStringNotContainsString('合成設定未配置', $html);
        self::assertStringContainsString('wrb_view=privacy', $html);
        $privacyPage = $page . '&wrb_view=privacy';
        $privacyHtml = $this->wordpress->request($privacyPage)['body'];
        self::assertStringContainsString('保存範囲と除外設定', $privacyHtml);
        self::assertStringContainsString('完全保存', $privacyHtml);
        self::assertMatchesRegularExpression('/name="preset" value="full"[^>]*checked/', $privacyHtml);
        self::assertStringContainsString('フォーム送信情報を除外', $privacyHtml);
        self::assertStringContainsString('wrb-policy-table', $privacyHtml);
        self::assertStringNotContainsString('name="accountId"', $privacyHtml);
        $privacyPost = $this->form($privacyHtml, 'privacy') + ['preset' => 'custom', 'category_forms' => 'exclude', 'ruleIntent' => 'add', 'ruleType' => 'table', 'tableName' => 'wp_demo_forms', 'ruleAction' => 'exclude'];
        $invalidPrivacy = $privacyPost; $invalidPrivacy['_wrb_live_nonce'] = 'invalid';
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-post.php', $invalidPrivacy)['status']);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $privacyPost)['status']);
        self::assertStringContainsString('wp_demo_forms', $this->wordpress->request($privacyPage)['body']);
        self::assertSame(200, $this->wordpress->request('/wp-admin/admin.php?page=wrb-synthetic-backup&wrb_view=privacy')['status']);
        $page .= '&wrb_view=storage';
        $html = $this->wordpress->request($page)['body'];
        $keys = Fixture::keys();
        $post = $this->form($html, 'save') + ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64),
            'recipient_0' => $keys[0]['public'], 'recipient_1' => $keys[1]['public'], 'retentionDays' => '30'];
        $invalid = $post; $invalid['_wrb_live_nonce'] = 'invalid';
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-post.php', $invalid)['status']);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $post)['status']);
        $html = $this->wordpress->request($page)['body'];
        self::assertStringNotContainsString(str_repeat('c', 64), $html);
        self::assertStringContainsString('wp_demo_forms', $this->wordpress->request($privacyPage)['body']);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $this->form($html, 'issue'))['status']);
        foreach ($keys as $slot => $key) {
            $html = $this->wordpress->request($page)['body'];
            $download = $this->wordpress->request('/wp-admin/admin-post.php', $this->form($html, 'download', (string) $slot));
            self::assertSame(200, $download['status']);
            $private = Fixture::temp(); Fixture::write($private, 'challenge.age', $download['body']);
            $identity = fopen($key['identity'], 'rb');
            try { (new AgeProcess(Fixture::runtime()['age']))->decrypt($private . '/challenge.age', $private . '/proof.json', $identity); } finally { fclose($identity); }
            $proof = json_decode(file_get_contents($private . '/proof.json'), true, 32, JSON_THROW_ON_ERROR);
            $confirmation = $this->form($html, 'confirm', (string) $slot); $confirmation['code'] = $proof['code'];
            self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $confirmation)['status']);
        }
        $private = Fixture::temp(); mkdir($private . '/objects', 0700);
        Fixture::write($this->wordpress->root(), 'proof.txt', 'real WordPress source');
        $script = <<<'PHP'
<?php
require WORDPRESS_ROOT . '/wp-load.php';
require TEST_TRANSPORT;
use WordPressR2Backup\{LiveSettings,LiveBackup,DirectR2Repository,PrivacyPolicy};
$settings=LiveSettings::load();
$settings['connectionVerified']=true;
$settings['policy']=['tables'=>[], 'fields'=>[], 'paths'=>['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'], 'scanPublic'=>true];
foreach($wpdb->get_col('SHOW TABLES') as $table) { $settings['policy']['tables'][$table]=in_array($table,['wp_users','wp_usermeta','wp_comments','wp_commentmeta'],true)?'exclude':'include'; }
$settings['policy']=PrivacyPolicy::migrateLegacy($settings['policy'],'wp_');
$settings['policy']['legacy']['fields']['wp_posts.post_content']='exclude';
$wpdb->query("INSERT INTO wp_posts(ID,post_title,post_content) VALUES(100,'kept','customer@example.test')");
$wpdb->query('CREATE TABLE wp_live_proof(id INT PRIMARY KEY, payload VARBINARY(10), optional_value VARCHAR(10) NULL)');
$wpdb->query("INSERT INTO wp_live_proof VALUES(1,X'000AFF',NULL)");
$settings['policy']['legacy']['tables']['wp_live_proof']='include';
LiveSettings::save($settings);
$repo=new DirectR2Repository(array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId'])),new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY));
$backend=new LiveBackup($settings,$repo);
$scan=$backend->scan(); if($scan['selected']<1 || $scan['tableCount']<1) throw new RuntimeException('Expected selected source and database tables');
$state=$backend->begin(); while($state['status']==='uploading') $state=$backend->step();
if(file_exists(ABSPATH.'.maintenance')) throw new RuntimeException('Maintenance was not released');
if($wpdb->get_var('SELECT post_content FROM wp_posts WHERE ID=100')!=='customer@example.test') throw new RuntimeException('Source was changed');
echo json_encode(['state'=>$state,'sourceDatabase'=>DB_NAME,'workspace'=>$backend->workspace(),'connection'=>array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId']))],JSON_THROW_ON_ERROR);
PHP;
        $script = str_replace(['WORDPRESS_ROOT', 'TEST_TRANSPORT', 'OBJECT_DIRECTORY'], [var_export($this->wordpress->root(), true), var_export(__DIR__ . '/MemoryR2Transport.php', true), var_export($private . '/objects', true)], $script);
        Fixture::write($private, 'capture.php', $script);
        $result = $this->command([PHP_BINARY, $private . '/capture.php']);
        self::assertJson($result, substr($result, 0, 1000));
        $result = json_decode($result, true, 64, JSON_THROW_ON_ERROR);
        $this->liveWorkspace = $result['workspace'];
        self::assertSame('complete', $result['state']['status']);
        foreach (glob($private . '/objects/*') as $objectFile) { self::assertStringNotContainsString('customer@example.test', file_get_contents($objectFile)); }
        $repo = new DirectR2Repository($result['connection'], new MemoryR2Transport($private . '/objects'));
        $snapshot = $result['state']['snapshotId'];
        $receipt = $repo->completed($snapshot)['manifest'];
        $repo->download($receipt, $private . '/manifest.age');
        $identity = fopen($keys[0]['identity'], 'rb');
        try { (new AgeProcess(Fixture::runtime()['age']))->decrypt($private . '/manifest.age', $private . '/manifest.json', $identity); } finally { fclose($identity); }
        $manifest = json_decode(file_get_contents($private . '/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(4, $manifest['version']);
        V4Format::validateRoot($manifest);
        self::assertSame('wordpress', $manifest['dataClass']);
        self::assertSame('r0001', $manifest['roots'][0]['id']);
        self::assertNotEmpty($manifest['pages']);
        self::assertGreaterThan(0, $manifest['counts']['packs']);
        self::assertArrayHasKey('legacy', $manifest['wordpress']['policy']);
        self::assertSame('exclude', $manifest['wordpress']['policy']['legacy']['fields']['wp_posts.post_content']);
        $pageKinds = [];
        foreach ($manifest['pages'] as $descriptor) {
            $pageKinds[] = $descriptor['kind'];
            $repo->download($descriptor['object'], $private . '/page-' . count($pageKinds) . '.age');
            $identity = fopen($keys[0]['identity'], 'rb');
            try { (new AgeProcess(Fixture::runtime()['age']))->decrypt($private . '/page-' . count($pageKinds) . '.age', $private . '/page-' . count($pageKinds) . '.json', $identity); } finally { fclose($identity); }
            $decoded = json_decode(file_get_contents($private . '/page-' . count($pageKinds) . '.json'), true, 512, JSON_THROW_ON_ERROR);
            V4Format::validatePage($decoded, $descriptor);
            self::assertStringNotContainsString('customer@example.test', file_get_contents($private . '/page-' . count($pageKinds) . '.json'));
        }
        foreach (['filesystem', 'database', 'packs'] as $kind) { self::assertContains($kind, $pageKinds); }
        $identity = fopen($keys[0]['identity'], 'rb');
        try { $export = (new Restorer(new AgeProcess(Fixture::runtime()['age']), $repo))->exportDatabase($snapshot, $identity, $private . '/recovery.sql'); }
        finally { fclose($identity); }
        self::assertFalse($export['databaseImported']);
        self::assertSame($manifest['database']['sha256'], hash_file('sha256', $private . '/recovery.sql'));
        self::assertSame(0600, fileperms($private . '/recovery.sql') & 0777);
        self::assertStringNotContainsString('customer@example.test', file_get_contents($private . '/recovery.sql'));
        $launch = <<<'PHP'
<?php
require WORDPRESS_ROOT . '/wp-load.php';
use WordPressR2Backup\{LiveSettings,LiveRunner};
$settings=LiveSettings::load();
$wpdb->query('CREATE TABLE wp_unreviewed_data(id INT PRIMARY KEY, payload TEXT)');
$wpdb->query("INSERT INTO wp_unreviewed_data VALUES(1,'unreviewed@example.test')");
echo json_encode((new LiveRunner($settings))->start(),JSON_THROW_ON_ERROR);
PHP;
        Fixture::write($private, 'launch.php', str_replace('WORDPRESS_ROOT', var_export($this->wordpress->root(), true), $launch));
        $queued = json_decode($this->command([PHP_BINARY, $private . '/launch.php']), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('queued', $queued['status']);
        Fixture::write($private, 'state.php', '<?php define("WP_INSTALLING", true); require ' . var_export($this->wordpress->root() . '/wp-load.php', true)
            . '; require_once ' . var_export($this->wordpress->root() . '/wp-content/plugins/wordpress-r2-backup/vendor/autoload.php', true)
            . '; echo json_encode((new WordPressR2Backup\\LiveRunner(WordPressR2Backup\\LiveSettings::load()))->state());');
        $deadline = microtime(true) + 20;
        do {
            usleep(100000);
            $workerState = json_decode($this->command([PHP_BINARY, $private . '/state.php']), true, 64, JSON_THROW_ON_ERROR);
        } while (in_array($workerState['status'], ['queued', 'capturing'], true) && microtime(true) < $deadline);
        self::assertSame('failed', $workerState['status'], 'Detached worker must execute and reject unreviewed data before transfer.');
        self::assertFileDoesNotExist($this->wordpress->root() . '/.maintenance');
        $inspectFile = getenv('WRB_UI_INSPECT_FILE');
        if (is_string($inspectFile) && $inspectFile !== '') {
            file_put_contents($inspectFile, json_encode(['url' => $this->wordpress->url('/wp-admin/admin.php?page=wrb-synthetic-backup&wrb_view=privacy'), 'root' => $this->wordpress->root(), 'credentials' => $this->wordpress->credentials()], JSON_THROW_ON_ERROR));
            chmod($inspectFile, 0600);
            $deadline = microtime(true) + 300;
            while (!is_file($inspectFile . '.done') && microtime(true) < $deadline) { usleep(200000); }
        }
        $this->wordpress->stop();
        $this->database->dropSchema($result['sourceDatabase']);
        $this->removeTree($this->wordpress->root());
        $kit = getenv('WRB_TEST_RECOVERY_KIT');
        if (is_string($kit) && $kit !== '') {
            $kitScript = <<<'PHP'
<?php
require KIT_AUTOLOAD;
require TEST_TRANSPORT;
use WordPressR2Backup\{AgeProcess,DirectR2Repository,Restorer};
$reader=new DirectR2Repository(CONNECTION,new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY));
$restorer=new Restorer(new AgeProcess(AGE_BINARY),$reader);
$key=fopen(IDENTITY_FILE,'rb');try{$restored=$restorer->restore(SNAPSHOT,$key,RESTORE_DIRECTORY);}finally{fclose($key);}
$key=fopen(IDENTITY_FILE,'rb');try{$sql=$restorer->exportDatabase(SNAPSHOT,$key,SQL_FILE);}finally{fclose($key);}
echo json_encode(['restore'=>$restored,'sql'=>$sql],JSON_THROW_ON_ERROR);
PHP;
            $kitScript = str_replace(['KIT_AUTOLOAD','TEST_TRANSPORT','CONNECTION','OBJECT_DIRECTORY','AGE_BINARY','IDENTITY_FILE','SNAPSHOT','RESTORE_DIRECTORY','SQL_FILE'],
                array_map(static fn ($value): string => var_export($value, true), [$kit . '/library/vendor/autoload.php', __DIR__ . '/MemoryR2Transport.php', $result['connection'], $private . '/objects', $kit . '/dependencies/age/age', $keys[1]['identity'], $snapshot, $private . '/kit-restore', $private . '/kit-database.sql']), $kitScript);
            Fixture::write($private, 'kit-restore.php', $kitScript);
            $kitResult = json_decode($this->command([PHP_BINARY, $private . '/kit-restore.php']), true, 64, JSON_THROW_ON_ERROR);
            self::assertFalse($kitResult['restore']['databaseImported']);
            self::assertSame('real WordPress source', file_get_contents($private . '/kit-restore/roots/r0001/proof.txt'));
            self::assertSame($manifest['database']['sha256'], hash_file('sha256', $private . '/kit-database.sql'));
        }
        foreach ($keys as $slot => $key) {
            $identity = fopen($key['identity'], 'rb');
            $target = $this->database->newSchema();
            try { $restored = (new Restorer(new AgeProcess(Fixture::runtime()['age']), $repo))->restore($snapshot, $identity, $private . '/restore-' . $slot, $this->database->client(), $target); }
            finally { fclose($identity); }
            self::assertTrue($restored['databaseImported']);
            self::assertSame('real WordPress source', file_get_contents($private . '/restore-' . $slot . '/roots/r0001/proof.txt'));
            self::assertSame([['1','000AFF','1']], $this->database->rows($target, 'SELECT id,HEX(payload),optional_value IS NULL FROM wp_live_proof'));
            self::assertSame([['0']], $this->database->rows($target, 'SELECT COUNT(*) FROM wp_users'));
            self::assertNotSame([['customer@example.test']], $this->database->rows($target, 'SELECT post_content FROM wp_posts WHERE ID=100'));
        }

    }

    private function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { $this->removeTree($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }

    private function form(string $html, string $command, ?string $slot = null): array
    {
        $dom = new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//form') as $form) {
            $fields = [];
            foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) { $fields[$input->getAttribute('name')] = $input->getAttribute('value'); }
            if (($fields['command'] ?? '') === $command && ($slot === null || ($fields['slot'] ?? '') === $slot)) { return $fields; }
        }
        throw new \RuntimeException('Expected live form was not found: ' . $command);
    }
    private function command(array $command, ?string $input = null): string
    {
        $output = tmpfile(); $error = tmpfile();
        $process = proc_open($command, [0=>['file',$input ?? '/dev/null','r'],1=>$output,2=>$error], $pipes);
        $exit = proc_close($process); rewind($output); rewind($error);
        $body = stream_get_contents($output); $message = stream_get_contents($error); fclose($output); fclose($error);
        self::assertSame(0, $exit, $message); return $body;
    }
}
