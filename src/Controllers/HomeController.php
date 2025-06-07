
<?php

namespace MyFramework\Controllers;

use Swoole\Coroutine as Co;

class HomeController
{
    public function index(int $userId): array
    {
        Co::sleep(1);
        return [
            'content' => "Welcome to the dashboard, User #{$userId}!",
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
