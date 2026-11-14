<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, PrivacyPolicy, WordPressSource};

final class WordPressExcludedForeignKeyTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    /** @dataProvider captureMethods */
    public function testExcludedRelationshipsKeepDefinitionsWithoutExportingRows(string $method): void
    {
        $definitions = [
            'wp_private_child' => "CREATE TABLE `wp_private_child` (\n  `ID` bigint NOT NULL,\n  `parent_id` bigint NOT NULL COMMENT 'Foreign Key For Primary ID',\n  `note` varchar(255) NOT NULL DEFAULT '' COMMENT 'REFERENCES ordinary ''quoted'' and \\'escaped\\' value',\n  CONSTRAINT `child_parent` FOREIGN KEY (`parent_id`) REFERENCES `wp_private_parent` (`ID`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `child_self` FOREIGN KEY (`ID`, `parent_id`) REFERENCES `wp_private_child` (`ID`, `parent_id`)\n) ENGINE=InnoDB",
            'wp_private_parent' => "CREATE TABLE `wp_private_parent` (\n  `ID` bigint NOT NULL,\n  `state` varchar(20) NOT NULL DEFAULT 'active',\n  `note` varchar(255) NOT NULL DEFAULT '' COMMENT 'ordinary ''quoted'' value'\n) ENGINE=InnoDB",
        ];
        $db = new ExcludedForeignKeyWpdbFixture($definitions);
        $private = Fixture::temp(); $keys = Fixture::keys();
        $source = new WordPressSource(Fixture::temp(), $db, new PrivacyPolicy(['tables' => array_fill_keys(array_keys($definitions), 'exclude')]), $private);
        $result = $source->$method(new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('a', 64));

        $expected = "SET @WRB_OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n"
            . implode(";\n", $definitions) . ";\nSET FOREIGN_KEY_CHECKS=1;\nSET SQL_MODE=@WRB_OLD_SQL_MODE;\n";
        self::assertSame(hash('sha256', $expected), $result['database']['sha256']);
        self::assertSame([0, 0], array_column($result['evidence']['tables'], 'rows'));
        self::assertSame(['exclude', 'exclude'], array_column($result['evidence']['tables'], 'action'));
        self::assertSame(2, $result['evidence']['exclusions']['tables']);
        self::assertTrue($db->locked);
        self::assertTrue($db->unlocked);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertSame('age', $file->getExtension());
        }
    }

    public function captureMethods(): array { return [['capture'], ['captureArchives']]; }

    /** @dataProvider unsupportedRelationships */
    public function testUnsupportedRelationshipsRemainRejected(string $constraint, array $actions, array $extraDefinitions = []): void
    {
        $definitions = [
            'wp_private_child' => "CREATE TABLE `wp_private_child` (\n  `ID` bigint NOT NULL,\n  " . $constraint . "\n) ENGINE=InnoDB",
            'wp_private_parent' => "CREATE TABLE `wp_private_parent` (\n  `ID` bigint NOT NULL\n) ENGINE=InnoDB",
        ] + $extraDefinitions;
        $source = new WordPressSource(Fixture::temp(), new ExcludedForeignKeyWpdbFixture($definitions), new PrivacyPolicy(['tables' => $actions]), Fixture::temp());
        $tables = [];
        foreach ($definitions as $name => $definition) { $tables[] = ['name' => $name, 'create' => $definition]; }
        $assert = new \ReflectionMethod(WordPressSource::class, 'assertSupportedDatabase'); $assert->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database foreign keys require a supported privacy relationship rule.');
        $assert->invoke($source, $tables);
    }

    public function unsupportedRelationships(): array
    {
        $key = 'CONSTRAINT `child_parent` FOREIGN KEY (`ID`) REFERENCES `wp_private_parent` (`ID`)';
        $excluded = ['wp_private_child' => 'exclude', 'wp_private_parent' => 'exclude'];
        return [
            'included child' => [$key, ['wp_private_child' => 'include', 'wp_private_parent' => 'exclude']],
            'included parent' => [$key, ['wp_private_child' => 'exclude', 'wp_private_parent' => 'include']],
            'unreviewed child' => [$key, ['wp_private_parent' => 'exclude']],
            'unreviewed parent' => [$key, ['wp_private_child' => 'exclude']],
            'missing reference' => [str_replace('`wp_private_parent`', '`wp_missing`', $key), $excluded + ['wp_missing' => 'exclude']],
            'partly excluded references' => [$key . ",\n  CONSTRAINT `other` FOREIGN KEY (`ID`) REFERENCES `wp_other` (`ID`)", $excluded + ['wp_other' => 'include'], ['wp_other' => 'CREATE TABLE `wp_other` (`ID` bigint)']],
            'qualified database' => [str_replace('REFERENCES `', 'REFERENCES `other_db`.`', $key), $excluded],
            'unquoted reference' => [str_replace('`wp_private_parent`', 'wp_private_parent', $key), $excluded],
            'escaped identifier' => [str_replace('`wp_private_parent`', '`wp_private``parent`', $key), $excluded + ['wp_private`parent' => 'exclude']],
            'double quoted reference' => [str_replace('`wp_private_parent`', '"wp_private_parent"', $key), $excluded],
            'comment in reference' => [str_replace('REFERENCES ', 'REFERENCES /* ambiguous */ ', $key), $excluded],
            'line comment' => [$key . "\n  -- ambiguous", $excluded],
            'hash comment' => [$key . "\n  # ambiguous", $excluded],
            'block comment' => [$key . "\n  /* ambiguous */", $excluded],
            'split constraint' => [str_replace(' REFERENCES ', "\n  REFERENCES ", $key), $excluded],
            'extra reference token' => [$key . " COMMENT 'REFERENCES'", $excluded],
            'unsupported match clause' => [$key . ' MATCH FULL', $excluded],
            'multiline string imitates a constraint' => ["`note` text COMMENT 'ambiguous\n  " . $key . "\n  '", $excluded],
            'real constraint and multiline imitation' => [$key . ",\n  `note` text COMMENT 'ambiguous\n  " . $key . "\n  '", $excluded],
        ];
    }
}

final class ExcludedForeignKeyWpdbFixture
{
    public string $last_error = '';
    public bool $locked = false;
    public bool $unlocked = false;
    private array $definitions;
    public function __construct(array $definitions) { $this->definitions = $definitions; }
    public function query(string $sql)
    {
        if (strpos($sql, 'LOCK TABLES ') === 0) { $this->locked = true; }
        if ($sql === 'UNLOCK TABLES') { $this->unlocked = true; }
        return 1;
    }
    public function get_var(string $sql)
    {
        if (strpos($sql, 'information_schema.') !== false) { return '0'; }
        if (strpos($sql, 'COUNT(*)') !== false) { return '1'; }
        if (strpos($sql, 'character_set_connection') !== false) { return 'utf8mb4'; }
        if (strpos($sql, 'CONNECTION_ID()') !== false) { return '10'; }
        throw new \RuntimeException('Unexpected synthetic database scalar query.');
    }
    public function get_results(string $sql, $format = null): array
    {
        if ($sql === 'SHOW FULL TABLES') {
            return array_map(static function (string $name): array { return ['table' => $name, 'type' => 'BASE TABLE']; }, array_keys($this->definitions));
        }
        if (strpos($sql, 'SHOW FULL COLUMNS FROM ') === 0) {
            return [['Field' => 'ID', 'Type' => 'bigint', 'Null' => 'NO', 'Key' => 'PRI', 'Extra' => '']];
        }
        if (strpos($sql, 'SHOW CREATE TABLE ') === 0) {
            preg_match('/`([^`]+)`/', $sql, $match);
            return [['Table' => $match[1], 'Create Table' => $this->definitions[$match[1]]]];
        }
        throw new \RuntimeException('Excluded synthetic rows must not be read.');
    }
}
