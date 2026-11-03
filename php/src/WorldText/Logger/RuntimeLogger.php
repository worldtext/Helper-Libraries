<?php

declare(strict_types=1);

namespace WorldText\Logger;

/**
 * Per-channel loggers for the PHP runtime.
 *
 * Mirrors the standard library's named loggers: an application can install a
 * logger for the `worldtext` channel once and every client created afterwards
 * uses it, without threading the logger through every constructor.
 *
 *     RuntimeLogger::set('worldtext', $myPsrLogger);
 *     $wt = new WorldText($token);          // logs to $myPsrLogger
 */
final class RuntimeLogger
{
    /** @var array<string, LoggerInterface> */
    private static array $loggers = [];

    /**
     * Channel used by {@see \WorldText\WorldText} unless overridden.
     */
    public const DEFAULT_CHANNEL = 'worldtext';

    /**
     * Install a logger for `$channel`.
     */
    public static function set(string $channel, LoggerInterface $logger): void
    {
        self::$loggers[$channel] = $logger;
    }

    /**
     * Remove any logger installed for `$channel`, restoring silence.
     */
    public static function clear(?string $channel = null): void
    {
        if ($channel === null) {
            self::$loggers = [];

            return;
        }

        unset(self::$loggers[$channel]);
    }

    /**
     * The logger for `$channel`: the installed one, else a null logger.
     */
    public static function get(string $channel): LoggerInterface
    {
        return self::$loggers[$channel] ?? new NullLogger();
    }

    private function __construct()
    {
    }
}