<?php

// File: bin/server.php

require dirname(__DIR__) . '/vendor/autoload.php';

use MyFramework\Core\SwooleRouter;
use MyFramework\Core\ConnectionManager;
use MyFramework\Controllers\HomeController;
use MyFramework\Controllers\ReportController;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Predis\Client as RedisClient;

// --- Configuration ---
$jwtSecretKey = 'your-super-secret-key-that-must-be-changed';

// --- Server Setup ---
$server = new Server("0.0.0.0", 8080);
$server->set([
    'worker_num' => 2,
    'enable_coroutine' => true,
]);
echo "Swoole WebSocket server started at ws://0.0.0.0:8080\n";

// --- Application Components ---
$router = new SwooleRouter();
$homeController = new HomeController();
$reportController = new ReportController();

// --- Define Routes ---
$router->addRoute('GET', '/', [$homeController, 'index']);
$router->addRoute('POST', '/reports', [$reportController, 'generate']);

// --- Server Event Handlers ---

$server->on('start', function(Server $server) use ($jwtSecretKey) {
    // In a worker process, start the Redis listener
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

<?php

// File: bin/worker.php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAmqplib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;

echo " [*] Background worker started. Waiting for jobs. To exit press CTRL+C\n";

$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();
$redis = new RedisClient();
$queueName = 'pdf_generation_queue';

$channel->queue_declare($queueName, false, true, false, false);

$callback = function ($message) use ($redis) {
    echo " [x] Received job: {$message->body}\n";
    $jobData = json_decode($message->body, true);
    $userId = $jobData['user_id'];

    echo " [>] Generating PDF for User #{$userId}...\n";
    sleep(10); // Simulate heavy work
    $reportUrl = "/downloads/report-{$userId}-" . time() . ".pdf";
    echo " [<] PDF generated: {$reportUrl}\n";

    $notificationChannel = "user_notifications:{$userId}";
    $notificationPayload = json_encode([
        'type' => 'job_complete',
        'event' => 'report_ready',
        'data' => [
            'url' => $reportUrl,
            'message' => 'Your report is now available!'
        ]
    ]);
    $redis->publish($notificationChannel, $notificationPayload);
    echo " [!] Notified Redis channel '{$notificationChannel}'\n";

    $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
    echo " [✔] Done.\n\n";
};

$channel->basic_qos(null, 1, null);
$channel->basic_consume($queueName, '', false, false, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

<?php

namespace MyFramework\Core;

use Swoole\WebSocket\Server;

class SwooleRouter
{
    private array $routes = [];

    public function addRoute(string $method, string $uri, callable $handler): void
    {
        $this->routes[$method][$uri] = $handler;
    }

    public function handle(Server $server, int $fd, int $userId, string $uri, string $method): void
    {
        $handler = $this->routes[$method][$uri] ?? null;

        if (!$handler) {
            $server->push($fd, json_encode(['error' => 'Route not found']));
            return;
        }

        go(function () use ($server, $fd, $userId, $uri, $handler) {
            // Pass the user ID to the controller action
            $responseData = $handler($userId);
            
            $server->push($fd, json_encode([
                'type' => 'route_response',
                'channel' => $uri,
                'data' => $responseData
            ]));
        });
    }
}

<?php

namespace MyFramework\Core;

class ConnectionManager
{
    // In-memory storage. For multi-server setup, use Redis instead.
    private static array $fdToUser = [];
    private static array $userToFd = [];

    public static function register(int $userId, int $fd): void
    {
        self::$fdToUser[$fd] = $userId;
        self::$userToFd[$userId] = $fd; // Note: this assumes one connection per user
    }

    public static function unregister(int $fd): void
    {
        if (isset(self::$fdToUser[$fd])) {
            $userId = self::$fdToUser[$fd];
            unset(self::$userToFd[$userId]);
            unset(self::$fdToUser[$fd]);
            echo "[Auth] Unregistered fd #{$fd} for User #{$userId}\n";
        }
    }

    public static function getUserIdByFd(int $fd): ?int
    {
        return self::$fdToUser[$fd] ?? null;
    }

    public static function getFdByUserId(int $userId): ?int
    {
        return self::$userToFd[$userId] ?? null;
    }
}

<?php

namespace MyFramework\Controllers;

use Swoole\Coroutine as Co;

class HomeController
{
    public function index(int $userId): array
    {
        Co::sleep(1); // Simulate some work
        return [
            'content' => "Welcome to the dashboard, User #{$userId}!",
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

<?php

namespace MyFramework\Controllers;

use PhpAmqplib\Connection\AMQPStreamConnection;
use PhpAmqplib\Message\AMQPMessage;

class ReportController
{
    public function generate(int $userId): array
    {
        $connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
        $channel = $connection->channel();
        $queueName = 'pdf_generation_queue';
        $channel->queue_declare($queueName, false, true, false, false);

        $jobPayload = json_encode(['user_id' => $userId]);

        $message = new AMQPMessage($jobPayload, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $channel->basic_publish($message, '', $queueName);

        echo " [x] Dispatched PDF generation job for User #{$userId}\n";

        $channel->close();
        $connection->close();
        
        return [
            'status' => 'queued',
            'message' => 'Your report generation has started.'
        ];
    }
}

// Sources:
// 1. https://github.com/15822681062/rabbitmq