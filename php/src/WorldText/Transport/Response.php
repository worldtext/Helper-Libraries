<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * A raw HTTP response: status, headers and body, before any interpretation.
 */
final class Response
{
    /**
     * @param array<string, string> $headers Header name in lower case => value.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * A response header, matched case-insensitively (headers are lower-cased).
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}