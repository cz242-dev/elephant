
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use MyFramework\Core\SwooleRouter;
use MyFramework\Core\ConnectionManager;
use MyFramework\Controllers\HomeController;
use MyFramework\Controllers\ReportController;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Predis\Client as RedisClient;

// --- Configuration ---
$jwtSecretKey = 'safl;sa;kfak;fjk;fm;kafaffafafasf';

// --- Server Setup ---
$server = new Server("0.0.0.0", 5000);
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
$server->on('start', function(Server $server) use ($jwtSecretKey) {
    go(function () use ($server) {
        $redis = new RedisClient();
        $pubsub = $redis->pubSubLoop();
        $pubsub->psubscribe('user_notifications:*');

        foreach ($pubsub as $message) {
            if ($message->kind !== 'pmessage') continue;

            echo "[Redis Listener] Received message on channel '{$message->channel}'\n";
            $parts = explode(':', $message->channel);
            $userId = end($parts);
            
            $fd = ConnectionManager::getFdByUserId($userId);

            if ($fd && $server->exist($fd)) {
                echo "[Redis Listener] Relaying message to User #{$userId} on fd #{$fd}\n";
                $server->push($fd, $message->payload);
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
