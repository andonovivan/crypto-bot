<?php
// Tiny PHP server that proxies every request to the Dockerized Laravel app on
// localhost:8090. Used as the runtime for the Claude Preview launcher so the
// preview's headless browser can reach the dashboard via the launcher's port.

error_reporting(E_ERROR);
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';
$target = 'http://localhost:8090' . $path;

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CUSTOMREQUEST => $method,
]);

$headers = [];
foreach (getallheaders() as $k => $v) {
    if (strcasecmp($k, 'Host') === 0) continue;
    $headers[] = "$k: $v";
}
if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headerStr = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

http_response_code($status);
$contentType = '';
foreach (explode("\r\n", $headerStr) as $line) {
    if (!str_contains($line, ':')) continue;
    if (preg_match('#^(content-encoding|transfer-encoding|connection|content-length):#i', $line)) continue;
    if (stripos($line, 'content-type:') === 0) {
        $contentType = strtolower(trim(substr($line, 13)));
    }
    header($line);
}

// Rewrite localhost:8090 → the proxy host so cross-origin module loads from
// the dashboard HTML resolve to this same proxy port (the headless browser
// blocks cross-origin module scripts that lack proper CORS headers).
if (str_contains($contentType, 'html') || str_contains($contentType, 'json')) {
    $body = str_replace('http://localhost:8090', 'http://localhost:' . ($_SERVER['SERVER_PORT'] ?? '8091'), $body);
}
echo $body;
