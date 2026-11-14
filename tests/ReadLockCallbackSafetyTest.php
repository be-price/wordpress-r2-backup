<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressDatabase;

require_once __DIR__.'/WordPressDatabaseTest.php';

/** Synthetic database lock callbacks only; does not create backups or perform recovery. */
final class ReadLockCallbackSafetyTest extends TestCase
{
    public function testSuccessfulVoidCallbackMayReturnNullAndReleasesLock(): void
    {
        $wpdb=new V4DatabaseWpdbFake(); $db=new WordPressDatabase($wpdb,new PrivacyPolicy(['version'=>2,'preset'=>'full'])); $called=false;
        $result=$db->withReadLock(static function() use ($wpdb,&$called): void { self::assertTrue($wpdb->locked); $called=true; });
        self::assertTrue($called); self::assertNull($result); self::assertFalse($wpdb->locked); self::assertSame(1,$wpdb->unlocks);
    }

    public function testCallbackExceptionPropagatesAndReleasesLock(): void
    {
        $wpdb=new V4DatabaseWpdbFake(); $db=new WordPressDatabase($wpdb,new PrivacyPolicy(['version'=>2,'preset'=>'full'])); $reached=false; $error=null;
        try { $db->withReadLock(static function() use (&$reached): void { $reached=true; throw new LogicException('synthetic callback error'); }); }
        catch (LogicException $caught) { $error=$caught; }
        self::assertTrue($reached); self::assertInstanceOf(LogicException::class,$error); self::assertSame('synthetic callback error',$error->getMessage());
        self::assertFalse($wpdb->locked); self::assertSame(1,$wpdb->unlocks);
    }

    public function testCallbackReturnValueIsPreservedAndReleasesLock(): void
    {
        $wpdb=new V4DatabaseWpdbFake(); $db=new WordPressDatabase($wpdb,new PrivacyPolicy(['version'=>2,'preset'=>'full']));
        $result=$db->withReadLock(static function(): array { return ['synthetic'=>true]; });
        self::assertSame(['synthetic'=>true],$result); self::assertFalse($wpdb->locked); self::assertSame(1,$wpdb->unlocks);
    }
}
