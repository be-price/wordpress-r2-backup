<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$wrbRoot = getenv('WRB_SITE_ROOT'); $wrbOrigin = getenv('WRB_LOCAL_URL');
if (!is_string($wrbRoot) || realpath($wrbRoot) !== $wrbRoot || !is_string($wrbOrigin)) { http_response_code(503); exit; }
$wrbOriginParts = parse_url($wrbOrigin);
if (!is_array($wrbOriginParts) || ($wrbOriginParts['scheme'] ?? '') !== 'http' || !in_array($wrbOriginParts['host'] ?? '', ['localhost', '127.0.0.1'], true) || !isset($wrbOriginParts['port'])) { http_response_code(503); exit; }
if (($_SERVER['HTTP_HOST'] ?? '') !== $wrbOriginParts['host'] . ':' . $wrbOriginParts['port']) { http_response_code(403); exit; }
// The database may hold a different public URL. While this isolated preview runs, every spelling of it points back at the listener.
$wrbTarget = getenv('WRB_TARGET_URL');
$wrbRewrite = static function (string $text) use ($wrbTarget, $wrbOrigin): string {
    if (!is_string($wrbTarget) || $wrbTarget === '') { return $text; }
    $target = preg_replace('~\Ahttps?://~i', '', rtrim($wrbTarget, '/')); $origin = substr($wrbOrigin, strlen('http://'));
    // Bare text naming the host is not a link and stays. The boundary keeps new.example.com from matching new.example.community.
    foreach ([
        ['(?:https?:)?//', $target, '(?![A-Za-z0-9_~%@-]|[.:](?=[A-Za-z0-9]))', 'http://' . $origin],
        ['(?:https?:)?\\\\/\\\\/', preg_quote(str_replace('/', '\\/', $target), '#'), '(?![A-Za-z0-9_~%@-]|[.:](?=[A-Za-z0-9]))', 'http:\\/\\/' . str_replace('/', '\\/', $origin)],
        ['(?:https?%3A)?%2F%2F', rawurlencode($target), '(?![A-Za-z0-9_~@-]|%3A|%40|\.(?=[A-Za-z0-9]))', rawurlencode('http://' . $origin)],
    ] as $index => [$prefix, $name, $boundary, $replacement]) {
        $name = $index === 0 ? preg_quote($name, '#') : $name;
        $text = preg_replace_callback('#(?<![A-Za-z0-9+.-])' . $prefix . $name . $boundary . '#i', static function () use ($replacement): string { return $replacement; }, $text);
        if (!is_string($text)) { return ''; }
    }
    return $text;
};
$wrbPolicy = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; worker-src 'self' blob:";
$wrbHeaders = static function () use ($wrbOrigin, $wrbPolicy, $wrbRewrite): void {
    foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = $wrbRewrite(trim(substr($header, 9))); $parts = parse_url($location);
            if ($location !== trim(substr($header, 9))) { header('Location: ' . $location); }
            $allowed = is_array($parts) && !isset($parts['user']) && !isset($parts['pass']) && strpos($location, '\\') === false && !preg_match('/[\x00-\x20\x7f]/', $location)
                && ((strpos($location, $wrbOrigin . '/') === 0 || $location === $wrbOrigin)
                    || (!isset($parts['scheme']) && !isset($parts['host']) && strpos($location, '//') !== 0));
            if (!$allowed) { header_remove('Location'); http_response_code(409); }
        }
        $name = strtolower(explode(':', $header, 2)[0]);
        if (in_array($name, ['refresh', 'link', 'report-to', 'reporting-endpoints', 'nel', 'content-security-policy-report-only', 'access-control-allow-origin', 'access-control-allow-credentials'], true)) { header_remove(explode(':', $header, 2)[0]); }
    }
    header('Content-Security-Policy: ' . $wrbPolicy); header('X-DNS-Prefetch-Control: off'); header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
};
$wrbHeaders(); header_register_callback($wrbHeaders);
$wrbPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('/[\x00-\x1f\x7f]/', $wrbPath) || strpos($wrbPath, '\\') !== false || preg_match('~(?:^|/)\.[^/]*(?:/|$)~', $wrbPath)) { http_response_code(403); exit; }
if (in_array($wrbPath, ['/wp-config.php', '/wp-cron.php', '/xmlrpc.php'], true)) { http_response_code(403); exit; }
if ($wrbPath === '/__wrb_isolation_check') { header('Content-Type: application/json'); $wrbMode = getenv('WRB_DATABASE_MODE'); if (!in_array($wrbMode, ['sqlite', 'mysql'], true)) { http_response_code(503); exit; } echo '{"localOnly":true,"database":"' . $wrbMode . '","externalConnections":"disabled"}'; exit; }
$wrbTarget = realpath($wrbRoot . $wrbPath);
if ($wrbTarget !== false && $wrbTarget !== $wrbRoot && strpos($wrbTarget, $wrbRoot . '/') !== 0) { http_response_code(403); exit; }
if ($wrbTarget !== false && is_dir($wrbTarget)) {
    if (substr($wrbPath, -1) !== '/') { header('Location: ' . $wrbOrigin . $wrbPath . '/' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')); http_response_code(302); exit; }
    $wrbTarget = realpath($wrbTarget . '/index.php');
}
if ($wrbTarget !== false && strpos($wrbTarget, $wrbRoot . '/') !== 0) { http_response_code(403); exit; }
if ($wrbTarget !== false && is_file($wrbTarget) && strtolower(pathinfo($wrbTarget, PATHINFO_EXTENSION)) !== 'php') {
    $wrbTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'map' => 'application/json', 'json' => 'application/json'];
    $wrbExtension = strtolower(pathinfo($wrbTarget, PATHINFO_EXTENSION));
    if (!isset($wrbTypes[$wrbExtension])) { http_response_code(403); exit; }
    header('Content-Type: ' . $wrbTypes[$wrbExtension]); header('Content-Length: ' . filesize($wrbTarget)); readfile($wrbTarget); exit;
}
if ($wrbTarget === false || !is_file($wrbTarget)) { $wrbTarget = $wrbRoot . '/index.php'; }
if (basename($wrbTarget) === 'wp-config.php') { http_response_code(403); exit; }
$_SERVER['DOCUMENT_ROOT'] = $wrbRoot; $_SERVER['SCRIPT_FILENAME'] = $wrbTarget;
$_SERVER['SCRIPT_NAME'] = substr($wrbTarget, strlen($wrbRoot)); $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
ob_start(static function (string $body) use ($wrbRewrite): string {
    $filtered = preg_replace('~<meta\b[^>]*http-equiv\s*=\s*["\x27]?refresh\b[^>]*>~i', '', $body);
    if ($filtered !== null) { $filtered = $wrbRewrite($filtered); }
    if ($filtered === null || ($filtered === '' && $body !== '')) { http_response_code(503); return 'Local response filtering failed.'; }
    return $filtered;
});
chdir(dirname($wrbTarget)); require $wrbTarget;
