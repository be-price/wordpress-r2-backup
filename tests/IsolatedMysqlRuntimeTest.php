<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;

/** Runtime discovery only: this suite never starts or imports a database. */
final class IsolatedMysqlRuntimeTest extends TestCase
{
    private $previous;
    protected function setUp(): void { $this->previous = getenv('WRB_TEST_MYSQL_BIN_DIR'); }
    protected function tearDown(): void { $this->previous === false ? putenv('WRB_TEST_MYSQL_BIN_DIR') : putenv('WRB_TEST_MYSQL_BIN_DIR=' . $this->previous); Fixture::cleanup(); }
    public function testExplicitRuntimeDirectoryIsCanonicalAndHasOneTrailingSlash(): void
    {
        $dir = Fixture::temp();
        foreach (['mysql', 'mysqldump', 'mysqld'] as $name) { file_put_contents($dir . '/' . $name, "#!/bin/sh\nexit 0\n"); chmod($dir . '/' . $name, 0700); }
        putenv('WRB_TEST_MYSQL_BIN_DIR=' . $dir . '/');
        self::assertSame($dir . '/', IsolatedMysql::mysqlBin());
        self::assertTrue(IsolatedMysql::available('mysql'));
    }
    public function testInvalidExplicitRuntimeDoesNotFallBackToAnotherServer(): void
    {
        putenv('WRB_TEST_MYSQL_BIN_DIR=' . Fixture::temp());
        self::assertFalse(IsolatedMysql::available('mysql'));
        $this->expectException(\RuntimeException::class);
        IsolatedMysql::mysqlBin();
    }
}
