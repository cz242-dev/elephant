
<?php

namespace MyFramework\Core;

use OpenSwoole\WebSocket\Server;

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
            $responseData = $handler($userId);
            
            $server->push($fd, json_encode([
                'type' => 'route_response',
                'channel' => $uri,
                'data' => $responseData
            ]));
        });
    }
}
