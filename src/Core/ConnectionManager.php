<?php

namespace MyFramework\Core;

class ConnectionManager
{
    private static array $fdToUser = [];
    private static array $userToFd = [];

    public static function register(int $userId, int $fd): void
    {
        self::$fdToUser[$fd] = $userId;
        self::$userToFd[$userId] = $fd;
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
