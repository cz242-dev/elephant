<?php

namespace MyFramework\Core;

use Swoole\WebSocket\Server;
use MyFramework\Core\ChannelManager;
use MyFramework\Core\RedisSubscriber;
use MyFramework\Core\Router;

class WebSocketServer extends Server
{
    private ChannelManager $channelManager;
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $this->channelManager = new ChannelManager();

        go(function () {
            $redis = new RedisSubscriber($this->channelManager);
            $redis->listen(['chat', 'news', 'updates']);
        });
    }

    public function onMessage(int $fd, string $msg): void
    {
        $request = json_decode($msg, true);

        if (isset($request['subscribe'])) {
            $this->channelManager->subscribe($this, $fd, $request['subscribe']);
            return;
        }

        $uri = $request['uri'] ?? '/';
        $method = $request['method'] ?? 'GET';
        $this->router->handle($this, $fd, $uri, $method);
    }

    public function onClose(int $fd): void
    {
        $this->channelManager->unsubscribe($fd);
        echo "Connection closed: {$fd}\n";
    }
}