<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * One HTTP request, fully built and ready for a {@see Transport} to send.
 *
 * Headers are keyed by name. The token is present here only as the value of the
 * `Authorization` header: {@see Request::describe()} never prints header values,
 * so a request can be logged without leaking the credential.
 */
final class Request
{
    /**
     * @param array<string, string> $headers Header name => value.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body = null,
    ) {
    }

    /**
     * A printable summary: method, path and header names. Never values.
     */
    public function describe(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return sprintf(
            '%s %s (headers: %s)',
            $this->method,
            is_string($path) ? $path : $this->url,
            implode(', ', array_keys($this->headers)),
        );
    }
}