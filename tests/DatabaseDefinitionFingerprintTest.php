<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\WordPressDatabase;

final class DatabaseDefinitionFingerprintTest extends TestCase
{
    public function testCollationMakesAnExpandedCharsetClauseEquivalent(): void
    {
        $implicit = 'CREATE TABLE `t` (`value` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL)';
        $explicit = 'CREATE TABLE `t` (`value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL)';
        self::assertSame(WordPressDatabase::definitionSha256($implicit, ''), WordPressDatabase::definitionSha256($explicit, ''));
        self::assertNotSame(WordPressDatabase::definitionSha256($explicit, ''), WordPressDatabase::definitionSha256(str_replace('unicode_ci', 'bin', $explicit), ''));
    }

    public function testLiteralDefaultsAndCommentsRemainPartOfTheDefinition(): void
    {
        $first = "CREATE TABLE `t` (`value` varchar(100) COMMENT 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin')";
        $second = str_replace('CHARACTER SET utf8mb4 COLLATE', 'COLLATE', $first);
        self::assertNotSame(WordPressDatabase::definitionSha256($first, ''), WordPressDatabase::definitionSha256($second, ''));
        $comment = 'CREATE TABLE `t` (`value` int /* CHARACTER SET utf8mb4 COLLATE utf8mb4_bin */)';
        self::assertNotSame(WordPressDatabase::definitionSha256($comment, ''), WordPressDatabase::definitionSha256(str_replace('CHARACTER SET utf8mb4 COLLATE', 'COLLATE', $comment), ''));
    }

    public function testQuotedIdentifiersAndSqlModeSpecificBackslashesRemainLiteral(): void
    {
        $name = 'CREATE TABLE `CHARACTER SET utf8mb4 COLLATE utf8mb4_bin` (`value` int)';
        self::assertNotSame(WordPressDatabase::definitionSha256($name, ''), WordPressDatabase::definitionSha256(str_replace('CHARACTER SET utf8mb4 COLLATE', 'COLLATE', $name), ''));
        $definition = "CREATE TABLE `t` (`value` varchar(10) COLLATE utf8mb4_bin DEFAULT 'a\\')";
        self::assertSame(WordPressDatabase::definitionSha256($definition, 'NO_BACKSLASH_ESCAPES'), WordPressDatabase::definitionSha256(str_replace('COLLATE utf8mb4_bin', 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', $definition), 'NO_BACKSLASH_ESCAPES'));
    }

    public function testMalformedDefinitionCannotProduceAValidFingerprint(): void
    {
        $this->expectException(\RuntimeException::class);
        WordPressDatabase::definitionSha256("CREATE TABLE `t` (`value` varchar(20) DEFAULT 'unclosed)", '');
    }
}
