<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * The API's `status` field, named for readability.
 *
 * `"0"` success, `"1"` permanent failure, `"2"`/`"3"` temporary (retryable).
 */
final class StatusFlag
{
    public const SUCCESS = '0';
    public const PERMANENT = '1';
    public const TEMPORARY = '2';
    public const DEFERRED = '3';

    /**
     * Whether a `status` value means "may be retried".
     */
    public static function isTemporary(?string $status): bool
    {
        return $status === self::TEMPORARY || $status === self::DEFERRED;
    }

    /**
     * Whether a `status` value means success.
     */
    public static function isSuccess(?string $status): bool
    {
        return $status === self::SUCCESS;
    }

    private function __construct()
    {
    }
}