<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressDatabase;

final class OnlineSnapshotFingerprintTest extends TestCase
{
    private function fingerprint(string $definition, string $sqlMode = ''): string
    {
        $source = new WordPressDatabase(new \stdClass(), new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $environment = new \ReflectionProperty($source, 'environment'); $environment->setAccessible(true);
        $environment->setValue($source, ['sqlMode' => $sqlMode]);
        $method = new \ReflectionMethod($source, 'snapshotFingerprint'); $method->setAccessible(true);
        return $method->invoke($source, [['name' => 'example', 'create' => $definition, 'columns' => [], 'order' => [], 'foreignKeys' => []]]);
    }

    public function testOnlyTableCounterIsIgnored(): void
    {
        $before = 'CREATE TABLE `example` (`id` int NOT NULL AUTO_INCREMENT, `note` varchar(80) COMMENT \'( ENGINE=InnoDB AUTO_INCREMENT=7 )\', PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4';
        $after = str_replace('ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT', 'ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT', $before);
        self::assertSame($this->fingerprint($before), $this->fingerprint($after));
        self::assertNotSame($this->fingerprint($before), $this->fingerprint(str_replace('AUTO_INCREMENT=7', 'AUTO_INCREMENT=8', $before)));
        self::assertNotSame($this->fingerprint($before), $this->fingerprint(str_replace('varchar(80)', 'varchar(81)', $before)));
    }

    public function testAnsiQuotedIdentifierDoesNotTreatBackslashAsEscape(): void
    {
        $before = 'CREATE TABLE "example" ("field\\" int NOT NULL) ENGINE=InnoDB AUTO_INCREMENT=2';
        $after = str_replace('AUTO_INCREMENT=2', 'AUTO_INCREMENT=3', $before);
        self::assertSame($this->fingerprint($before, 'ANSI_QUOTES'), $this->fingerprint($after, 'ANSI_QUOTES'));
    }
}
