<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
if (!str_starts_with($config['url'], 'http://127.0.0.1:')) { exit(1); }
$curl = curl_init($config['url']); $headers = [];
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($config['fields']), CURLOPT_COOKIEFILE => $config['cookie'],
    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int { if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))] = trim($value); } return strlen($line); }]);
$body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
if ($body === false) { exit(1); }
echo json_encode(['status' => $status, 'headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR);
