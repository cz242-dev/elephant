
<?php
// Simple static file server for testing
$requestUri = $_SERVER['REQUEST_URI'];

if ($requestUri === '/' || $requestUri === '/index.html') {
    header('Content-Type: text/html');
    readfile('index.html');
    exit;
}

// For WebSocket connections, redirect to server
if (strpos($_SERVER['HTTP_UPGRADE'] ?? '', 'websocket') !== false) {
    header('HTTP/1.1 426 Upgrade Required');
    header('Upgrade: websocket');
    header('Connection: Upgrade');
    exit('WebSocket upgrade required. Use ws://localhost:5000');
}

// Default response
echo "Swoole server should be running on port 5000. Use 'php bin/server.php' to start.";
