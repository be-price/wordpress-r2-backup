<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, BackupArchive};

final class PublicContentIntegrationTest extends TestCase
{
    public function testPublicBackupPreservesContentAndOmitsPrivateRowsWithEmptyForeignKeyTables(): void
    {
        $database = IsolatedMysql::start();
        $wordpress = null;
        try {
            $wordpress = IsolatedWordPress::start($database);
            $private = Fixture::temp();
            $site = Fixture::temp();
            foreach (['public.jpg', 'public-small.jpg', 'private.jpg', 'orphan.jpg', 'document.pdf'] as $file) {
                Fixture::write($site, 'wp-content/uploads/2026/10/' . $file, 'synthetic-media-' . $file);
            }
            Fixture::write($site, 'proof.txt', 'public-site-code');
            $keys = Fixture::keys();
            $script = <<<'PHP'
<?php
require WP_ROOT . '/wp-load.php';
use WordPressR2Backup\{AgeProcess, PrivacyPolicy, WordPressSource};
$wpdb->query("UPDATE wp_options SET option_value='https://public.example.invalid' WHERE option_name IN ('home','siteurl')");
$wpdb->query("INSERT INTO wp_posts(ID,post_type,post_status,post_password,post_parent,post_mime_type,post_title,post_name,post_content) VALUES
(900,'post','publish','',0,'','Public','public','Public business contact business@example.invalid'),
(901,'post','draft','',0,'','Private draft','private-draft','private-person@example.invalid'),
(902,'post','private','',0,'','Private','private','private-person@example.invalid'),
(903,'post','publish','protected',0,'','Protected','protected','private-person@example.invalid'),
(904,'attachment','inherit','',900,'image/jpeg','Public image','public-image',''),
(905,'attachment','inherit','',901,'image/jpeg','Private image','private-image',''),
(906,'attachment','inherit','',0,'image/jpeg','Public orphan','public-orphan',''),
(907,'attachment','inherit','',900,'application/pdf','Document','document',''),
(908,'acf-field','publish','',0,'','Private configuration','private-configuration','private-person@example.invalid')");
foreach ([904=>'public.jpg',905=>'private.jpg',906=>'orphan.jpg',907=>'document.pdf'] as $id=>$name) {
    $wpdb->insert($wpdb->postmeta,['post_id'=>$id,'meta_key'=>'_wp_attached_file','meta_value'=>'2026/10/'.$name]);
}
$wpdb->insert($wpdb->postmeta,['post_id'=>904,'meta_key'=>'_wp_attachment_metadata','meta_value'=>serialize(['file'=>'2026/10/public.jpg','sizes'=>['small'=>['file'=>'public-small.jpg']]])]);
$wpdb->insert($wpdb->postmeta,['post_id'=>901,'meta_key'=>'private_notes','meta_value'=>'private-person@example.invalid']);
$wpdb->insert($wpdb->postmeta,['post_id'=>908,'meta_key'=>'private_notes','meta_value'=>'private-person@example.invalid']);
$wpdb->insert($wpdb->options,['option_name'=>'fixture_private_contact','option_value'=>'private-person@example.invalid','autoload'=>'no']);
$wpdb->query('CREATE TABLE wp_private_parent(id INT PRIMARY KEY)');
$wpdb->query("CREATE TABLE wp_private_child(id INT PRIMARY KEY,parent_id INT COMMENT 'Foreign Key For Primary ID',CONSTRAINT child_parent_fk FOREIGN KEY(parent_id) REFERENCES wp_private_parent(id))");
$wpdb->query('INSERT INTO wp_private_parent VALUES(1)');
$wpdb->query('INSERT INTO wp_private_child VALUES(1,1)');
$policy=new PrivacyPolicy(['publicContentOnly'=>true,'scanPublic'=>false,'excludedOptions'=>['mailserver_login','fixture_private_contact'],
 'excludedRows'=>['wp_posts'=>['ID'=>['908']]],
 'tables'=>['wp_private_parent'=>'exclude','wp_private_child'=>'exclude']],$wpdb->prefix);
$source=new WordPressSource(SITE_ROOT,$wpdb,$policy,PRIVATE_ROOT);
$scan=$source->scan();
if($scan['unresolved']!==0){$pending=[];foreach($scan['tables'] as $table){if($table['action']==='review')$pending[]=$table['name'];foreach($table['fields'] as $field)if($table['action']==='include'&&$field['action']==='review')$pending[]=$table['name'].'.'.$field['name'];}foreach($scan['files'] as $file)if($file['action']==='review')$pending[]=$file['path'];throw new RuntimeException('Public fixture has unresolved targets: '.json_encode($pending));}
echo json_encode($source->captureArchives(new AgeProcess(AGE_BINARY),RECIPIENTS,str_repeat('b',64)),JSON_THROW_ON_ERROR);
PHP;
            $script = str_replace(['WP_ROOT','SITE_ROOT','PRIVATE_ROOT','AGE_BINARY','RECIPIENTS'], array_map(static fn ($v): string => var_export($v, true), [$wordpress->root(),$site,$private,Fixture::runtime()['age'],array_column($keys,'public')]), $script);
            Fixture::write($private, 'capture.php', $script);
            $output = tmpfile(); $error = tmpfile();
            $process = proc_open([PHP_BINARY,$private.'/capture.php'],[0=>['file','/dev/null','r'],1=>$output,2=>$error],$pipes);
            self::assertIsResource($process);
            $exit=proc_close($process); rewind($output); rewind($error);
            self::assertSame(0,$exit,(string)stream_get_contents($error));
            $capture=json_decode((string)stream_get_contents($output),true,64,JSON_THROW_ON_ERROR);
            fclose($output); fclose($error);
            $age=new AgeProcess(Fixture::runtime()['age']);
            $identity=fopen($keys[0]['identity'],'rb');
            try { $age->decrypt($capture['database']['cipherFile'],$private.'/verified.sql',$identity); } finally { fclose($identity); }
            self::assertStringNotContainsString('private-person@example.invalid',file_get_contents($private.'/verified.sql'));
            $target=$database->newSchema();
            $database->client()->importIntoEmpty($target,$private.'/verified.sql');
            self::assertSame([['Public business contact business@example.invalid','']],$database->rows($target,'SELECT post_content,post_password FROM wp_posts WHERE ID=900'));
            self::assertSame([['0']],$database->rows($target,'SELECT COUNT(*) FROM wp_posts WHERE ID IN (901,902,903,905,907,908)'));
            self::assertSame([['0']],$database->rows($target,'SELECT COUNT(*) FROM wp_postmeta WHERE post_id IN (901,902,903,905,907,908)'));
            self::assertSame([['2']],$database->rows($target,'SELECT COUNT(*) FROM wp_posts WHERE ID IN (904,906)'));
            self::assertSame([['0']],$database->rows($target,"SELECT COUNT(*) FROM wp_options WHERE option_name='fixture_private_contact'"));
            self::assertSame([['0']],$database->rows($target,'SELECT COUNT(*) FROM wp_private_child'));
            self::assertSame([['1']],$database->rows($target,"SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_private_child' AND REFERENCED_TABLE_NAME='wp_private_parent'"));
            $paths=[];
            foreach ($capture['archives'] as $i=>$archive) {
                $identity=fopen($keys[1]['identity'],'rb');
                try { $age->decrypt($archive['cipherFile'],$private.'/part-'.$i.'.tar',$identity); } finally { fclose($identity); }
                foreach(BackupArchive::inspect($private.'/part-'.$i.'.tar')['entries'] as $entry)$paths[]=$entry['path'];
            }
            self::assertContains('wp-content/uploads/2026/10/public.jpg',$paths);
            self::assertContains('wp-content/uploads/2026/10/public-small.jpg',$paths);
            self::assertContains('wp-content/uploads/2026/10/orphan.jpg',$paths);
            self::assertNotContains('wp-content/uploads/2026/10/private.jpg',$paths);
            self::assertNotContains('wp-content/uploads/2026/10/document.pdf',$paths);
        } finally {
            if($wordpress!==null)$wordpress->stop();
            $database->stop(); Fixture::cleanup();
        }
    }
}
