<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\RunningSiteFiles;

final class RunningSiteFilesTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    /** @dataProvider acceptedBootstrap */
    public function testGeneratedConfigurationAcceptsSafeBootstrapWithoutWarnings(string $database, bool $rootDefined, bool $flagsDefined): void
    {
        [$exit, $output, $errors] = $this->loadConfiguration($database, $rootDefined ? 'same' : 'undefined', $flagsDefined ? array_fill_keys(self::flags(), true) : []);
        self::assertSame(0, $exit, $errors); self::assertSame('', $errors);
        self::assertSame('booted', $output);
    }

    public function acceptedBootstrap(): array
    {
        $cases = [];
        foreach (['mysql', 'sqlite'] as $database) {
            $cases[$database . '-direct'] = [$database, false, false];
            $cases[$database . '-wp-load'] = [$database, true, false];
            $cases[$database . '-isolated-harness'] = [$database, true, true];
        }
        return $cases;
    }

    public function testGeneratedConfigurationRejectsAnExistingDifferentRootBeforeBootstrap(): void
    {
        [$exit, $output, $errors] = $this->loadConfiguration('mysql', 'different', []);
        self::assertSame(0, $exit, $errors); self::assertSame('', $errors); self::assertSame('rejected', $output);
    }

    /** @dataProvider unsafeIsolationFlags */
    public function testGeneratedConfigurationRejectsNonTrueIsolationFlagsBeforeBootstrap(string $flag, $value): void
    {
        [$exit, $output, $errors] = $this->loadConfiguration('sqlite', 'undefined', [$flag => $value]);
        self::assertSame(0, $exit, $errors); self::assertSame('', $errors); self::assertSame('rejected', $output);
    }

    public function unsafeIsolationFlags(): array
    {
        $cases = [];
        foreach (self::flags() as $flag) {
            foreach ([false, 1, 'true', null] as $i => $value) { $cases[$flag . '-' . $i] = [$flag, $value]; }
        }
        return $cases;
    }

    private static function flags(): array
    {
        return ['DISABLE_WP_CRON', 'WP_HTTP_BLOCK_EXTERNAL', 'AUTOMATIC_UPDATER_DISABLED', 'DISALLOW_FILE_MODS'];
    }

    private function loadConfiguration(string $database, string $rootDefined, array $flags): array
    {
        $root = Fixture::temp();
        $constants = $database === 'mysql' ? "define('DB_NAME', 'synthetic');\ndefine('DB_HOST', '127.0.0.1');\n" : "define('DB_ENGINE', 'sqlite');\n";
        file_put_contents($root . '/wp-config.php', RunningSiteFiles::configuration($constants, 'http://127.0.0.1:18889', null, 'wp_'));
        file_put_contents($root . '/wp-settings.php', <<<'SETTINGS'
<?php
foreach (['DISABLE_WP_CRON', 'WP_HTTP_BLOCK_EXTERNAL', 'AUTOMATIC_UPDATER_DISABLED', 'DISALLOW_FILE_MODS'] as $flag) {
    if (!defined($flag) || constant($flag) !== true) { throw new LogicException('Unsafe bootstrap reached wp-settings.'); }
}
if (ABSPATH !== __DIR__ . '/' || WP_HOME !== 'http://127.0.0.1:18889' || WP_SITEURL !== WP_HOME || $table_prefix !== 'wp_') { throw new LogicException('Startup values changed.'); }
echo 'booted';
SETTINGS
        );
        $script = 'set_error_handler(static function ($severity, $message) { throw new ErrorException($message, 0, $severity); });';
        if ($rootDefined !== 'undefined') { $script .= 'define("ABSPATH", ' . var_export($rootDefined === 'same' ? $root . '/' : $root . '/other/', true) . ');'; }
        foreach ($flags as $name => $value) { $script .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');'; }
        // Only the explicit fail-closed exception counts as rejection; a redefinition warning must fail the test.
        $script .= 'try { require ' . var_export($root . '/wp-config.php', true) . '; } catch (RuntimeException $error) { echo "rejected"; }';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $output, $errors];
    }
}
