<?php

namespace MyFramework\Controllers;

use OpenSwoole\Coroutine as Co;

class HomeController
{
    public function index(int $userId): array
    {
        return [
            'content' => "Welcome to the dashboard, User #{$userId}!",
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
