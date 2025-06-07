
<?php
// Simple static file server for testing
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Remove query string for file serving
$path = parse_url($requestUri, PHP_URL_PATH);

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html');
    if (file_exists('index.html')) {
        readfile('index.html');
    } else {
        echo "<!DOCTYPE html><html><head><title>Server Not Running</title></head><body>";
        echo "<h1>Swoole Server Not Running</h1>";
        echo "<p>Please start the Swoole server with: <code>php bin/server.php</code></p>";
        echo "<p>The WebSocket server should be running on port 5000.</p>";
        echo "</body></html>";
    }
    exit;
}

// Default response for other requests
http_response_code(404);
echo "File not found. Start the Swoole server with: php bin/server.php";
