<?php

declare(strict_types=1);

namespace WorldText\Logger;

/**
 * A null logger: discards everything. The default, so a library emits nothing
 * unless the application asks for it.
 */
final class NullLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
    }
}