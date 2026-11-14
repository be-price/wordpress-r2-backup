<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, ManagedCapture, Restorer, SyntheticFileSource, SyntheticHttpRepository};

final class WordPressAdminIntegrationTest extends TestCase
{
    private ?IsolatedMysql $sourceDatabase = null;
    private ?IsolatedMysql $wordpressDatabase = null;
    private ?IsolatedObjectServer $objects = null;
    private ?IsolatedWordPress $wordpress = null;

    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); } if ($this->objects !== null) { $this->objects->stop(); } if ($this->sourceDatabase !== null) { $this->sourceDatabase->stop(); } if ($this->wordpressDatabase !== null) { $this->wordpressDatabase->stop(); } Fixture::cleanup();
    }

    public function testRealWordPressAuthorizationTwoKeyConfirmationFailureAndSameJobResume(): void
    {
        $private = Fixture::temp();
        foreach (['staging', 'operations', 'index'] as $name) { mkdir($private . '/' . $name, 0700); }
        $this->sourceDatabase = IsolatedMysql::start($private); $this->wordpressDatabase = IsolatedMysql::start();
        $schema = $this->sourceDatabase->newSchema();
        $this->sourceDatabase->execute($schema, "CREATE TABLE inno(id INT PRIMARY KEY,v VARCHAR(20)) ENGINE=InnoDB; CREATE TABLE myisam(id INT PRIMARY KEY,v VARCHAR(20)) ENGINE=MyISAM; INSERT INTO inno VALUES(1,'fixed'); INSERT INTO myisam VALUES(1,'fixed');");
        $source = SyntheticFileSource::create($private); $source->write('日本語/data.bin', "\0\xfffixture"); $source->mkdir('empty');
        $unit = str_repeat('e', 64); $backend = Fixture::temp(); $cursor = ManagedCapture::initializeIndex($private . '/index', $unit);
        $this->objects = IsolatedObjectServer::startManaged($backend, $unit, str_repeat('f', 64), $cursor);
        foreach (['writer', 'reader'] as $role) { copy($this->objects->connectionFile($role), $private . '/' . $role . '.json'); chmod($private . '/' . $role . '.json', 0600); }
        $bin = IsolatedMysql::mysqlBin();
        $profile = ['format' => 'wrb-synthetic-admin-runtime-v1', 'privateRoot' => $private, 'sourceDirectory' => dirname($source->root()),
            'stagingDirectory' => $private . '/staging', 'operationsDirectory' => $private . '/operations', 'indexDirectory' => $private . '/index',
            'writerConnectionFile' => $private . '/writer.json', 'readerConnectionFile' => $private . '/reader.json', 'ageBinary' => Fixture::runtime()['age'],
            'mysqlBinary' => $bin . 'mysql', 'dumpBinary' => $bin . 'mysqldump', 'mysqlDefaultsFile' => $this->sourceDatabase->defaultsFile(),
            'mysqlWorkspace' => $this->sourceDatabase->workspace(), 'syntheticSchema' => $schema];
        $profileFile = $private . '/profile.json'; file_put_contents($profileFile, json_encode($profile, JSON_THROW_ON_ERROR)); chmod($profileFile, 0600);
        $this->wordpress = IsolatedWordPress::start($this->wordpressDatabase, $profileFile);
        $page = '/wp-admin/tools.php?page=wrb-synthetic-backup';
        self::assertSame(302, $this->wordpress->request($page, null, 'guest')['status']);
        self::assertSame(403, $this->wordpress->request($page, null, 'reader')['status']);
        $html = $this->wordpress->request($page)['body']; self::assertStringContainsString('合成', $html); self::assertStringNotContainsString($private, $html);
        $registration = $this->form($html, 'wrb_register_keys'); $keys = Fixture::keys();
        $registration['recipient_1'] = $keys[0]['public']; $registration['recipient_2'] = $keys[1]['public'];
        $invalid = $registration; $invalid['_wrb_nonce'] = 'invalid';
        $rejected = $this->wordpress->request('/wp-admin/admin-post.php', $invalid);
        self::assertSame(303, $rejected['status']); self::assertStringContainsString('wrb_result=nonce_invalid', $rejected['headers']['location']);
        self::assertSame(405, $this->wordpress->request('/wp-admin/admin-post.php?' . http_build_query($registration))['status']);
        $invalid = $registration; $invalid['recipient_1'] = [$keys[0]['public']];
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $invalid)['status']);
        self::assertSame([], glob($private . '/operations/*'));
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $registration)['status']);
        $html = $this->wordpress->request($page)['body'];
        if (!$this->hasForm($html, 'wrb_download_challenge')) {
            self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $this->form($html, 'wrb_issue_challenge'))['status']);
        }
        foreach ($keys as $index => $key) {
            $slot = (string) ($index + 1); $html = $this->wordpress->request($page)['body'];
            $download = $this->wordpress->request('/wp-admin/admin-post.php', $this->form($html, 'wrb_download_challenge', $slot));
            self::assertSame(200, $download['status']); self::assertStringContainsString('no-store', $download['headers']['cache-control']);
            $directory = Fixture::temp(); $cipher = $directory . '/challenge.age'; file_put_contents($cipher, $download['body']); chmod($cipher, 0600);
            $identity = fopen($key['identity'], 'rb');
            try { (new AgeProcess(Fixture::runtime()['age']))->decrypt($cipher, $directory . '/proof.json', $identity); } finally { fclose($identity); }
            $proof = json_decode(file_get_contents($directory . '/proof.json'), true, 32, JSON_THROW_ON_ERROR);
            $confirmation = $this->form($html, 'wrb_confirm_challenge', $slot); $confirmation['code'] = $proof['code'];
            self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $confirmation)['status']);
            self::assertStringNotContainsString($proof['code'], $this->wordpress->request($page)['body']);
        }
        $html = $this->wordpress->request($page)['body']; $start = $this->form($html, 'wrb_start');
        $blocker = $backend . '/' . $unit . '/commits'; file_put_contents($blocker, 'interrupted'); chmod($blocker, 0600);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $start)['status']);
        $html = $this->wordpress->request($page)['body']; $resume = $this->form($html, 'wrb_resume');
        $jobFiles = glob($private . '/operations/*/jobs/*/job.json');
        self::assertCount(1, $jobFiles);
        $jobBefore = json_decode(file_get_contents($jobFiles[0]), true, 64, JSON_THROW_ON_ERROR)['data'];
        self::assertNull($jobBefore['result']); unlink($blocker);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $resume)['status']);
        self::assertSame([], (new SyntheticHttpRepository($private . '/reader.json'))->listCompleted());
        sleep(31);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $resume)['status']);
        $records = (new SyntheticHttpRepository($private . '/reader.json'))->listCompleted(); self::assertCount(1, $records);
        $jobAfter = json_decode(file_get_contents($jobFiles[0]), true, 64, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($jobBefore['jobId'], $jobAfter['jobId']); self::assertSame($jobBefore['snapshotId'], $jobAfter['snapshotId']);
        self::assertSame($jobBefore['candidates'], $jobAfter['candidates']);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $start)['status']);
        self::assertCount(1, (new SyntheticHttpRepository($private . '/reader.json'))->listCompleted());
        $html = $this->wordpress->request($page)['body']; self::assertStringContainsString($records[0]['snapshotId'], $html); self::assertStringContainsString('目録本文', $html);
        self::assertStringNotContainsString($private, $html); self::assertStringNotContainsString($schema, $html);
        foreach ($keys as $key) {
            $identity = fopen($key['identity'], 'rb');
            try { $inspection = (new Restorer(new AgeProcess(Fixture::runtime()['age']), new SyntheticHttpRepository($private . '/reader.json')))->inspect($records[0]['snapshotId'], $identity); }
            finally { fclose($identity); }
            self::assertSame(2, $inspection['manifestVersion']); self::assertSame('reported', $inspection['captureEvidence']);
        }
        $startNext = $this->form($html, 'wrb_start');
        $this->objects->fault('pause-before-complete');
        $overlap = $this->wordpress->launchPost('/wp-admin/admin-post.php', $startNext);
        try {
            $this->objects->waitForPause();
            $second = $this->wordpress->request('/wp-admin/admin-post.php', $startNext, 'admin', 3);
            self::assertSame(303, $second['status']); self::assertStringContainsString('wrb_result=busy', $second['headers']['location']);
        } finally { $this->objects->releasePublication(); }
        $output = stream_get_contents($overlap['pipes'][1]); fclose($overlap['pipes'][1]);
        $error = stream_get_contents($overlap['pipes'][2]); fclose($overlap['pipes'][2]);
        self::assertSame(0, proc_close($overlap['process']), $error);
        self::assertSame(303, json_decode($output, true, 32, JSON_THROW_ON_ERROR)['status']);
        self::assertCount(2, (new SyntheticHttpRepository($private . '/reader.json'))->listCompleted());
        self::assertCount(2, glob($private . '/operations/*/jobs/*/job.json'));
        $adminFile = $this->wordpress->root() . '/wp-content/plugins/wordpress-r2-backup/src/SyntheticAdmin.php';
        $original = file_get_contents($adminFile);
        $boundaries = [
            'before-prepare' => '            $jobId = $this->runtime->captureFor($id)->prepare(',
            'after-prepare' => '            $names = [$jobId];',
            'after-complete' => "        if (\$operation['status'] !== 'complete')",
        ];
        foreach ($boundaries as $boundary => $needle) {
            $marker = $private . '/crash-' . $boundary;
            file_put_contents($marker, 'armed'); chmod($marker, 0600);
            $injection = '        if (is_file(' . var_export($marker, true) . ')) { unlink(' . var_export($marker, true) . '); file_put_contents(' . var_export($marker . '.pid', true) . ', (string) getmypid()); posix_kill(getmypid(), SIGKILL); }' . "\n";
            self::assertSame(1, substr_count($original, $needle));
            file_put_contents($adminFile, str_replace($needle, $injection . $needle, $original));
            $startCrash = $this->form($this->wordpress->request($page)['body'], 'wrb_start');
            $interrupted = false; $response = null;
            try {
                $response = $this->wordpress->request('/wp-admin/admin-post.php', $startCrash);
                $interrupted = $response['status'] === 502;
            } catch (\RuntimeException $error) {
                self::assertStringStartsWith('Fixture HTTP request failed (cURL errno ', $error->getMessage()); $interrupted = true;
            } finally { file_put_contents($adminFile, $original); }
            self::assertTrue($interrupted, $boundary . ' ' . json_encode($response));
            self::assertFileDoesNotExist($marker);
            $stoppedPid = (int) file_get_contents($marker . '.pid');
            self::assertGreaterThan(1, $stoppedPid);
            $deadline = microtime(true) + 3;
            while (posix_kill($stoppedPid, 0) && microtime(true) < $deadline) { usleep(10000); }
            $processState = trim((string) shell_exec('/bin/ps -o stat= -p ' . $stoppedPid));
            self::assertTrue(!posix_kill($stoppedPid, 0) || str_starts_with($processState, 'Z'), 'The request worker must have stopped.');
            $pending = glob($private . '/operations/*/jobs/*/job.json'); sort($pending);
            $pendingSnapshots = [];
            foreach ($pending as $file) { $pendingSnapshots[] = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR)['data']['snapshotId']; }
            $resumeCrash = $this->form($this->wordpress->request($page)['body'], 'wrb_resume');
            self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $resumeCrash)['status']);
            $completed = (new SyntheticHttpRepository($private . '/reader.json'))->listCompleted();
            $expected = 3 + array_search($boundary, array_keys($boundaries), true);
            self::assertCount($expected, $completed);
            self::assertCount($expected, glob($private . '/operations/*/jobs/*/job.json'));
            if ($boundary !== 'before-prepare') {
                $resumed = glob($private . '/operations/*/jobs/*/job.json'); sort($resumed);
                self::assertSame($pending, $resumed);
                self::assertEqualsCanonicalizing($pendingSnapshots, array_column($completed, 'snapshotId'));
            }
            self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $startCrash)['status']);
            self::assertCount($expected, (new SyntheticHttpRepository($private . '/reader.json'))->listCompleted());
        }
    }

    private function hasForm(string $html, string $action): bool
    {
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        return (new \DOMXPath($document))->query('//form[.//input[@name="action" and @value="' . $action . '"]]')->length > 0;
    }

    private function form(string $html, string $action, ?string $slot = null): array
    {
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET); $xpath = new \DOMXPath($document);
        $query = '//form[.//input[@name="action" and @value="' . $action . '"]]';
        if ($slot !== null) { $query .= '[.//input[@name="slot" and @value="' . $slot . '"]]'; }
        $forms = $xpath->query($query); self::assertSame(1, $forms->length, 'Expected one ' . $action . ' form.');
        $fields = []; foreach ($xpath->query('.//input[@name]', $forms->item(0)) as $input) { $fields[$input->getAttribute('name')] = $input->getAttribute('value'); }
        return $fields;
    }
}
