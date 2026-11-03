<?php

declare(strict_types=1);

namespace WorldText\Logger;

/**
 * Writes debug lines to the PHP error log.
 *
 * Used only when the PHP runtime registers a logger for this channel through
 * `RuntimeLogger::set()`. It prints the message *and* its context, so a token
 * reaching the context would be visible; the client always passes the redacted
 * form. The token is additionally scrubbed as a last line of defence.
 */
final class ErrorLogLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
        $line = 'worldtext DEBUG: ' . self::interpolate($message, $context);

        error_log(\WorldText\Token::scrub($line));
    }

    /**
     * Fill `{name}` placeholders from the context, PSR-3 style.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $name => $value) {
            $replacements['{' . $name . '}'] = match (true) {
                is_scalar($value) => (string) $value,
                $value === null => 'null',
                default => get_debug_type($value),
            };
        }

        return strtr($message, $replacements);
    }
}