<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

final class MemoryR2Transport
{
    private string $directory;
    public function __construct(string $directory) { $this->directory = $directory; }
    public function __invoke(array $request, callable $consume): array
    {
        $path = parse_url($request['url'], PHP_URL_PATH);
        $mapFile = $this->directory . '/objects.json';
        $map = is_file($mapFile) ? json_decode(file_get_contents($mapFile), true, 32, JSON_THROW_ON_ERROR) : [];
        $file = $this->directory . '/' . hash('sha256', $path) . '.cipher';
        $status = 200; $headers = []; $body = '';
        if ($request['method'] === 'PUT') {
            if (isset($map[$path])) { $status = 412; }
            else { $bytes = stream_get_contents($request['body']); if (!str_ends_with($path, '.json') && !str_starts_with($bytes, 'age-encryption.org/v1')) { throw new \RuntimeException('Unencrypted object upload.'); } file_put_contents($file, $bytes); $map[$path] = basename($file); }
        } elseif ($request['method'] === 'HEAD') {
            if (!isset($map[$path])) { $status = 404; } else { $headers['content-length'] = (string) filesize($file); }
        } elseif ($request['method'] === 'DELETE') {
            if (is_file($file)) { unlink($file); } unset($map[$path]); $status = 204;
        } elseif (strpos($request['url'], '?') !== false) {
            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
            $body = '<ListBucketResult><Prefix>' . htmlspecialchars($query['prefix'], ENT_XML1) . '</Prefix><IsTruncated>false</IsTruncated>';
            foreach (array_keys($map) as $key) { $name = substr($key, strlen('/backup-test/')); if (str_starts_with($name, $query['prefix'])) { $body .= '<Contents><Key>' . htmlspecialchars($name, ENT_XML1) . '</Key></Contents>'; } }
            $body .= '</ListBucketResult>';
        } elseif (!isset($map[$path])) { $status = 404; }
        else { $body = file_get_contents($file); }
        file_put_contents($mapFile, json_encode($map, JSON_THROW_ON_ERROR));
        foreach (str_split($body, 4096) as $chunk) { if ($consume($chunk) !== strlen($chunk)) { break; } }
        return ['status' => $status, 'headers' => $headers];
    }
}
