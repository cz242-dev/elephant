<?php

namespace MyFramework\Core;

use Swoole\WebSocket\Server;

class ChannelManager
{
    private array $channels = [];

    public function subscribe(Server $server, int $fd, string $channel): void
    {
        $this->channels[$channel][$fd] = $fd;
    }

    public function unsubscribe(int $fd): void
    {
        foreach ($this->channels as $channel => &$clients) {
            unset($clients[$fd]);
        }
    }

    public function broadcast(Server $server, string $channel, string $message): void
    {
        if (!isset($this->channels[$channel])) return;

        foreach ($this->channels[$channel] as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode([
                    'type' => 'broadcast',
                    'channel' => $channel,
                    'message' => $message
                ]));
            }
        }
    }
}