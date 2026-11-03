<?php

declare(strict_types=1);

namespace WorldText;

/**
 * Token redaction.
 *
 * An API token is a credential: it must never reach a log line, an exception
 * message or a `var_dump()`. Everything that displays or describes a token goes
 * through {@see Token::redact()}, which reports only its length.
 */
final class Token
{
    /** Placeholder for an absent token. */
    public const NONE = '<none>';

    /**
     * Tokens the client has seen, so a stray copy can be scrubbed from a message.
     *
     * No code path is supposed to put a token into text; this is the belt to
     * {@see Token::redact()}'s braces, and costs only a short string per client.
     *
     * @var list<string>
     */
    private static array $known = [];

    /** Bound on {@see Token::$known}; older entries are dropped. */
    private const MAX_KNOWN = 64;

    /**
     * Return a log-safe form of a token: never the token itself.
     */
    public static function redact(?string $token): string
    {
        if ($token === null || $token === '') {
            return self::NONE;
        }

        return sprintf('<redacted:%d chars>', strlen($token));
    }

    /**
     * Register a token so that {@see Token::scrub()} can recognise it.
     *
     * Called once by the client constructor. The token itself is never copied
     * into a message; this list exists only to be compared against.
     */
    public static function remember(string $token): void
    {
        if ($token === '' || in_array($token, self::$known, true)) {
            return;
        }

        self::$known[] = $token;
        if (count(self::$known) > self::MAX_KNOWN) {
            array_shift(self::$known);
        }
    }

    /**
     * Replace any registered token that appears in `$text` with its redacted
     * form. Text that contains no token is returned unchanged.
     *
     * Longest first, so a token that is a prefix of another is fully replaced.
     */
    public static function scrub(string $text): string
    {
        if (self::$known === []) {
            return $text;
        }

        $known = self::$known;
        usort($known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $replacements = [];
        foreach ($known as $token) {
            if (str_contains($text, $token)) {
                $replacements[$token] = self::redact($token);
            }
        }

        return $replacements === [] ? $text : strtr($text, $replacements);
    }

    /**
     * Not instantiable: this is a namespace for the static helpers above.
     */
    private function __construct()
    {
    }
}