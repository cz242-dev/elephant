<?php

namespace MyFramework\Service;

use Swoole\Coroutine\Redis;
use MyFramework\Core\ConnectionManager;
use MyFramework\Core\ChannelManager;

class RedisSubscriber
{
    public Redis $redis;
    public ChannelManager $channelManager;

    public function __construct(ChannelManager $channelManager)
    {
        $this->redis = new Redis();
        $this->channelManager = $channelManager;
    }

    public function listen(array $channels): void
    {
        $this->redis->pubSubLoop()->subscribe(...$channels);

        foreach ($this->redis->pubSubLoop() as $message) {
            if ($message->kind === 'message') {
                $this->channelManager->broadcast($message->channel, $message->payload);
            }
        }
    }
}
