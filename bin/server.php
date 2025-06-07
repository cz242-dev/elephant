<?php

if (!extension_loaded('openswoole')) {
    die("Error: The OpenSwoole extension is not loaded. Please install and enable it.\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

use MyFramework\Core\SwooleRouter;
use MyFramework\Core\ConnectionManager;
use MyFramework\Controllers\HomeController;
use MyFramework\Controllers\ReportController;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame; // Explicitly use the Frame class
use OpenSwoole\WebSocket\Server as WebSocketServer;
use OpenSwoole\Server as SwooleServer; // Alias the OpenSwoole\Server class
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use OpenSwoole\Coroutine\Redis;

// --- Configuration ---
$jwtSecretKey = 'jfjfjfjfjakfjakldfjkldsjfklsjkdlf';

// --- Server Setup ---
$server = new WebSocketServer("0.0.0.0", 5000);
$server->set([
    'worker_num' => 2,
    'enable_coroutine' => true,
]);
echo "Swoole WebSocket server started at ws://0.0.0.0:5000\n";

// --- Application Components ---
$router = new SwooleRouter();
$homeController = new HomeController();
$reportController = new ReportController();

// --- Define Routes ---
$router->addRoute('GET', '/', [$homeController, 'index']);
$router->addRoute('POST', '/reports', [$reportController, 'generate']);

// --- Server Event Handlers ---
$server->on('start', function(SwooleServer $server) use ($jwtSecretKey) {
    go(function () use ($server) {
        $redis = new \OpenSwoole\Coroutine\Redis();
        $redis->connect('redis', 6379); // Use the service name from docker-compose
        $redis->setOption(\OpenSwoole\Coroutine\Redis::OPT_READ_TIMEOUT, -1); // Block forever

        $channels = $redis->psubscribe(['user_notifications:*']);
        if ($channels === false) {
            echo "[Redis Listener] Failed to subscribe to channels\n";
            return;
        }

        while (true) {
            $message = $redis->recv();
            if ($message === false) {
                echo "[Redis Listener] Redis connection closed or error\n";
                break;
            }
            // $message format: [ 'pmessage', pattern, channel, payload ]
            if ($message[0] !== 'pmessage') continue;

            echo "[Redis Listener] Received message on channel '{$message[2]}'\n";
            $parts = explode(':', $message[2]);
            $userId = end($parts);

            $fd = \MyFramework\Core\ConnectionManager::getFdByUserId($userId);

            if ($fd && $server->exist($fd)) {
                echo "[Redis Listener] Relaying message to User #{$userId} on fd #{$fd}\n";
                $server->push($fd, $message[3]);
            }
        }
    });
});

$server->on('open', function (Server $server, Request $request) use ($jwtSecretKey) {
    echo "[Auth] Connection attempt from #{$request->fd}\n";
    $token = $request->get['token'] ?? null;

    if (!$token) {
        $server->close($request->fd, 1008, "Authentication failed: No token provided.");
        return;
    }

    try {
        $payload = JWT::decode($token, new Key($jwtSecretKey, 'HS256'));
        $userId = $payload->sub;
        ConnectionManager::register($userId, $request->fd);
        echo "[Auth] Success! User #{$userId} connected on fd #{$request->fd}\n";
        $server->push($request->fd, json_encode(['type' => 'auth_success', 'message' => 'Connection successful.']));
    } catch (\Exception $e) {
        $server->close($request->fd, 1008, "Authentication failed: Invalid token.");
    }
});

$server->on('message', function (Server $server, Frame $frame) use ($router) {
    $request = json_decode($frame->data, true);
    if (!isset($request['uri']) || !isset($request['method'])) {
        $server->push($frame->fd, json_encode(['error' => 'Invalid request format']));
        return;
    }
    
    $userId = ConnectionManager::getUserIdByFd($frame->fd);
    if (!$userId) {
        $server->push($frame->fd, json_encode(['error' => 'Unauthorized']));
        return;
    }

    $router->handle($server, $frame->fd, $userId, $request['uri'], $request['method']);
});

$server->on('close', function (Server $server, int $fd) {
    ConnectionManager::unregister($fd);
});

$server->start();
