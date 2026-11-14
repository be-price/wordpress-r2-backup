<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DedupIndex, LocalRepository, Restorer, SnapshotWriter};

final class DatabaseRoundTripTest extends TestCase
{
    private IsolatedMysql $server;

    protected function setUp(): void { $this->server = IsolatedMysql::start(); }
    protected function tearDown(): void
    {
        if (isset($this->server)) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testAllTablesSurviveSourceDeletion(): void
    {
        $definer = $this->server->sharedImporter();
        $schema = $this->server->newSchema();
        $this->server->execute($schema, strtr(<<<'SQL'
CREATE TABLE wp_posts (id INT PRIMARY KEY, title VARCHAR(100) NOT NULL);
CREATE TABLE wp_options (name VARCHAR(50) PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE custom_records (id INT PRIMARY KEY, payload VARBINARY(100));
INSERT INTO wp_posts VALUES (1,'旧世代');
INSERT INTO wp_options VALUES ('site','合成サイト');
INSERT INTO custom_records VALUES (1,X'000AFF');
CREATE TRIGGER record_post AFTER INSERT ON wp_posts FOR EACH ROW INSERT INTO custom_records VALUES (NEW.id,X'FF00');
SQL, ['CREATE TRIGGER' => "CREATE DEFINER=$definer TRIGGER"]));
        $definitions = $this->definitions($schema);
        $root = Fixture::temp(); $unit = str_repeat('b',64); $idx = Fixture::temp();
        $writer = LocalRepository::forWriting($root,$unit);
        $reader = LocalRepository::forRecovery($root,$unit);
        $keys = Fixture::keys(); $age = new AgeProcess(Fixture::runtime()['age']);
        $capture = new SnapshotWriter($age,$writer,DedupIndex::openOrCreate($idx,$writer));
        $site = Fixture::site(['version.txt'=>'old','deleted.txt'=>'exists']);
        $dumpRoot = Fixture::temp();
        $snapshots = [];
        foreach (['old','new'] as $version) {
            if ($version === 'new') {
                $this->server->execute($schema,"UPDATE wp_posts SET title='新世代'; UPDATE custom_records SET payload=X'FF1000'");
                Fixture::write($site,'version.txt','new'); unlink($site . '/deleted.txt');
            }
            $sql = $dumpRoot . '/' . $version . '.sql';
            $this->server->client()->dump($schema,$sql);
            $snapshots[$version] = $capture->capture($site,$sql,array_column($keys,'public'));
            unlink($sql);
        }
        $this->server->dropSchema($schema);
        foreach (glob($site . '/*') as $file) { unlink($file); }
        unlink($site . '/.wrb-synthetic'); rmdir($site);
        unlink($idx . '/index.jsonl'); unlink($idx . '/unit.lock'); rmdir($idx);
        $restorer = new Restorer($age,$reader);
        foreach ($keys as $key) {
            foreach ($snapshots as $version => $snapshot) {
                $target = $this->server->newSchema();
                $destination = Fixture::temp() . '/restored';
                $identity = fopen($key['identity'],'rb');
                try { $result = $restorer->restore($snapshot,$identity,$destination,$this->server->client(),$target); }
                finally { fclose($identity); }
                self::assertTrue($result['databaseImported']);
                self::assertSame($version,file_get_contents($destination . '/version.txt'));
                self::assertSame($version === 'old',file_exists($destination . '/deleted.txt'));
                self::assertSame([['custom_records'],['wp_options'],['wp_posts']],$this->server->rows($target,'SHOW TABLES'));
                self::assertSame([['1',$version === 'old' ? '旧世代' : '新世代']],$this->server->rows($target,'SELECT * FROM wp_posts'));
                self::assertSame([['site','合成サイト']],$this->server->rows($target,'SELECT * FROM wp_options'));
                self::assertSame([['1',$version === 'old' ? '000AFF' : 'FF1000']],$this->server->rows($target,'SELECT id,HEX(payload) FROM custom_records'));
                self::assertSame($definitions,$this->definitions($target));
                $this->server->execute($target,"INSERT INTO wp_posts VALUES(2,'trigger')");
                self::assertSame([['FF00']],$this->server->rows($target,'SELECT HEX(payload) FROM custom_records WHERE id=2'));
            }
        }
    }

    public function testDatabaseFailureDoesNotPublishFilesAndRetryRequiresEmptyDatabase(): void
    {
        $root = Fixture::temp(); $unit = str_repeat('c',64); $keys=Fixture::keys();
        $writer=LocalRepository::forWriting($root,$unit); $reader=LocalRepository::forRecovery($root,$unit);
        $sql=Fixture::temp() . '/bad.sql'; file_put_contents($sql,'CREATE TABLE partial_data(id INT); THIS IS INVALID SQL;');
        $age=new AgeProcess(Fixture::runtime()['age']);
        $snapshot=(new SnapshotWriter($age,$writer,DedupIndex::openOrCreate(Fixture::temp(),$writer)))->capture(Fixture::site(['file'=>'content']),$sql,array_column($keys,'public'));
        $target=$this->server->newSchema(); $destination=Fixture::temp() . '/restored';
        foreach ([0,1] as $attempt) {
            $identity=fopen($keys[0]['identity'],'rb');
            try {
                (new Restorer($age,$reader))->restore($snapshot,$identity,$destination,$this->server->client(),$target);
                self::fail('Invalid database import must not publish files');
            } catch (\RuntimeException $ignoredError) {
                self::assertFileDoesNotExist($destination);
                self::assertSame([['partial_data']],$this->server->rows($target,'SHOW TABLES'));
            } finally { fclose($identity); }
        }
    }

    public function testPublicationInterruptionRequiresFreshDatabaseAndPreservesImportedData(): void
    {
        $root = Fixture::temp();
        $unit = str_repeat('e', 64);
        $keys = Fixture::keys();
        $writer = LocalRepository::forWriting($root, $unit);
        $sql = Fixture::temp() . '/valid.sql';
        file_put_contents($sql, 'CREATE TABLE only_data(id INT PRIMARY KEY); INSERT INTO only_data VALUES(9);');
        $site = Fixture::site(['000-file' => 'verified content', 'zzz-file' => 'last content']);
        for ($i = 0; $i < 512; $i++) {
            mkdir($site . '/directory-' . $i, 0700);
        }
        $age = new AgeProcess(Fixture::runtime()['age']);
        $snapshot = (new SnapshotWriter($age, $writer, DedupIndex::openOrCreate(Fixture::temp(), $writer)))->capture($site, $sql, array_column($keys, 'public'));
        $restorer = new Restorer($age, LocalRepository::forRecovery($root, $unit));
        $target = $this->server->newSchema();
        $destination = Fixture::temp() . '/restored';
        $bin = IsolatedMysql::mysqlBin();
        RestorerTest::interruptPublication($root, $unit, $snapshot, $keys[0]['identity'], $destination, [$bin . 'mysql', $bin . 'mysqldump', $this->server->defaultsFile(), $this->server->workspace(), $target]);
        self::assertSame([['9']], $this->server->rows($target, 'SELECT * FROM only_data'));
        $this->server->execute($target, 'INSERT INTO only_data VALUES(10)');
        $identity = fopen($keys[0]['identity'], 'rb');
        try {
            $restorer->resume($snapshot, $identity, $destination, $this->server->client(), $target);
            self::fail('The successful import must not run twice on the same target.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('new empty', $error->getMessage());
            self::assertSame([['9'], ['10']], $this->server->rows($target, 'SELECT * FROM only_data ORDER BY id'));
        } finally {
            fclose($identity);
        }
        $fresh = $this->server->newSchema();
        $identity = fopen($keys[1]['identity'], 'rb');
        try {
            $result = $restorer->resume($snapshot, $identity, $destination, $this->server->client(), $fresh);
        } finally {
            fclose($identity);
        }
        self::assertTrue($result['databaseImported']);
        self::assertSame([['9']], $this->server->rows($fresh, 'SELECT * FROM only_data'));
        self::assertSame('verified content', file_get_contents($destination . '/000-file'));
        self::assertSame('last content', file_get_contents($destination . '/zzz-file'));
        self::assertCount(516, scandir($destination));
        self::assertSame([], glob(dirname($destination) . '/.wrb-restore-*'));
    }

    private function definitions(string $schema): array
    {
        $result=[];
        $result['tables']=$this->server->rows($schema,'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
        $result['columns']=$this->server->rows($schema,'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,COLUMN_TYPE,COLUMN_KEY,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
        $result['indexes']=$this->server->rows($schema,'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
        $result['trigger']=$this->server->rows($schema,'SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT,ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME');
        return $result;
    }

    public function testDatabaseFailureCanResumeWithNewEmptyDatabase(): void
    {
        $root=Fixture::temp(); $unit=str_repeat('d',64); $keys=Fixture::keys();
        $writer=LocalRepository::forWriting($root,$unit);
        $sql=Fixture::temp() . '/valid.sql'; file_put_contents($sql,'CREATE TABLE only_data(id INT PRIMARY KEY); INSERT INTO only_data VALUES(9);');
        $age=new AgeProcess(Fixture::runtime()['age']);
        $snapshot=(new SnapshotWriter($age,$writer,DedupIndex::openOrCreate(Fixture::temp(),$writer)))->capture(Fixture::site(['file'=>'content']),$sql,array_column($keys,'public'));
        $restorer=new Restorer($age,LocalRepository::forRecovery($root,$unit));
        $blocked=$this->server->newSchema();
        $this->server->execute($blocked,'CREATE TABLE existing_data(id INT); INSERT INTO existing_data VALUES(5)');
        $destination=Fixture::temp() . '/restored';
        $identity=fopen($keys[0]['identity'],'rb');
        try {
            $restorer->restore($snapshot,$identity,$destination,$this->server->client(),$blocked);
            self::fail('A nonempty database must not be changed');
        } catch (\RuntimeException $ignoredError) {
            self::assertFileDoesNotExist($destination);
            self::assertSame([['5']],$this->server->rows($blocked,'SELECT * FROM existing_data'));
        } finally { fclose($identity); }
        $target=$this->server->newSchema(); $identity=fopen($keys[1]['identity'],'rb');
        try { $result=$restorer->resume($snapshot,$identity,$destination,$this->server->client(),$target); }
        finally { fclose($identity); }
        self::assertTrue($result['databaseImported']);
        self::assertSame('content',file_get_contents($destination . '/file'));
        self::assertSame([['9']],$this->server->rows($target,'SELECT * FROM only_data'));
    }
}
