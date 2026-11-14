<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class PhpCompatibilityTest extends TestCase
{
    public function testPluginBootstrapsOnTheSupportedPhpRuntime(): void
    {
        $php = getenv('WRB_COMPAT_PHP_BIN') ?: PHP_BINARY;
        $code = 'define("ABSPATH", sys_get_temp_dir()); function add_action() {} function add_filter() {} require '
            . var_export(dirname(__DIR__) . '/wordpress-r2-backup.php', true) . '; echo "plugin booted";';
        $process = proc_open([$php, '-d', 'display_errors=stderr', '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('plugin booted', $output);
    }
}
