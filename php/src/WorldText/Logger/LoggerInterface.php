<?php

declare(strict_types=1);

namespace WorldText\Logger;

/**
 * The small slice of PSR-3 this client needs.
 *
 * PSR-3 is not available without Composer, and this library takes no
 * dependencies, so the shape of a PSR-3 logger is declared locally. Anything
 * that is a PSR-3 logger satisfies this interface structurally, so it can be
 * passed straight to {@see \WorldText\WorldText} when the application does
 * have one.
 */
interface LoggerInterface
{
    /**
     * @param string               $message Template with `{placeholder}` fields.
     * @param array<string, mixed> $context Field values.
     */
    public function debug(string $message, array $context = []): void;
}