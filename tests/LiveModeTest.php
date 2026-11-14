<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveAdmin;

final class LiveModeTest extends TestCase
{
    public function testLegacyRequestRemainsMaintenanceAndRequiresBothConfirmations(): void
    {
        self::assertSame('maintenance', LiveAdmin::startMode(['writers'=>'1','downtime'=>'1']));
        foreach ([[],['writers'=>'1'],['downtime'=>'1']] as $input) {
            try { LiveAdmin::startMode($input); self::fail('Missing maintenance confirmation accepted'); } catch (\RuntimeException $expected) {}
        }
    }

    public function testOnlineRequiresExplicitRiskAcknowledgmentAndRejectsUnknownMode(): void
    {
        self::assertSame('online', LiveAdmin::startMode(['mode'=>'online','onlineAck'=>'1']));
        foreach ([['mode'=>'online'],['mode'=>'online','onlineAck'=>'0'],['mode'=>'typo','onlineAck'=>'1']] as $input) {
            try { LiveAdmin::startMode($input); self::fail('Unsafe request accepted'); } catch (\RuntimeException $expected) {}
        }
    }
}
