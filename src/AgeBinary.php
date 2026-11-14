<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class AgeBinary
{
    public static function path(): string
    {
        $platform = strtolower(PHP_OS_FAMILY) . '-' . (in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64');
        $hashes = ['linux-amd64' => 'eb7dd1b518f0a307c99cd97782623c5321da049154b04acd2d98d21aa7bc9b2c',
            'darwin-arm64' => '4012dfc2725883beafb710894af4f599b7a94f8c8e0f51f02cc96ab8df33915e'];
        $file = dirname(__DIR__) . '/bin/' . $platform . '/age';
        if (!isset($hashes[$platform]) || !is_file($file) || is_link($file) || !is_executable($file)
            || !hash_equals($hashes[$platform], hash_file('sha256', $file)) || !is_callable('proc_open')) {
            throw new \RuntimeException('このサーバーで暗号化ツールを実行できません。対応する公式age実行ファイルとproc_openを確認してください。');
        }
        return $file;
    }
}
