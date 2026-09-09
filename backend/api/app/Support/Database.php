<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The single PDO connection for a request.
 *
 * PHP-FPM gives each request a fresh process state, so there is no connection
 * pool to manage — one lazily-created connection per request is the whole
 * lifecycle. Shared hosting caps concurrent MySQL connections (doc 16.6), which
 * is the reason not to open more than one.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $socket = config('database.socket');
        $charset = (string) config('database.charset', 'utf8mb4');
        $database = (string) config('database.database');

        $dsn = is_string($socket) && $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $database, $charset)
            : sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                (string) config('database.host'),
                (string) config('database.port'),
                $database,
                $charset,
            );

        try {
            /** @var array<int,mixed> $options */
            $options = config('database.options', []);

            self::$connection = new PDO(
                $dsn,
                (string) config('database.username'),
                (string) config('database.password', ''),
                $options,
            );
        } catch (PDOException $e) {
            // The message carries credentials in some drivers; never let it
            // reach a response body.
            throw new RuntimeException('Database connection failed', 0, $e);
        }

        return self::$connection;
    }

    /** Used by /health/db, which must distinguish "down" from "misconfigured". */
    public static function isReachable(): bool
    {
        try {
            self::connection()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$connection = null;
    }
}
