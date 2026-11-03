<?php

declare(strict_types=1);

namespace WorldText;

/**
 * The client's secret-bearing state, held in a property that prints redacted.
 *
 * PHP has no way to stop `print_r()`, `var_dump()` or a debugger from reading a
 * private property, so the token must not live in one. This class keeps it and
 * renders as {@see Token::redact()} whenever it is displayed; the client keeps
 * the real value reachable only through {@see Credentials::token()}, which is
 * used for exactly one purpose: building the `Authorization` header.
 *
 * `print_r()` on an object uses its `__debugInfo()` hook, so even a dump of the
 * whole client shows `<redacted:N chars>` rather than the token.
 */
final class Credentials
{
    private readonly string $token;

    private readonly string $baseUrl;

    private readonly float $timeout;

    /**
     * @param string $token   The API token.
     * @param string $baseUrl Base URL, without a trailing slash.
     * @param float  $timeout Per-request timeout in seconds.
     */
    public function __construct(string $token, string $baseUrl, float $timeout)
    {
        $this->token = $token;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
    }

    /**
     * The token. Only {@see WorldText} should call this, and only to build the
     * Authorization header.
     *
     * @internal
     */
    public function token(): string
    {
        return $this->token;
    }

    /**
     * Base URL without a trailing slash.
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Per-request timeout in seconds.
     */
    public function timeout(): float
    {
        return $this->timeout;
    }

    /**
     * What `print_r()` and `var_dump()` show: no token.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'token' => Token::redact($this->token),
            'timeout' => (string) $this->timeout,
        ];
    }

    /**
     * `var_export()` ignores {@see Credentials::__debugInfo()}, so the export
     * form is given explicitly: it re-imports as an object that refuses to be
     * used rather than one carrying the token.
     *
     * @return array<string, mixed>
     */
    public static function __set_state(array $properties): never
    {
        throw new \LogicException('Credentials cannot be exported');
    }

    /**
     * What string conversion shows: no token.
     */
    public function __toString(): string
    {
        return sprintf(
            "Credentials(baseUrl='%s', token=%s)",
            $this->baseUrl,
            Token::redact($this->token),
        );
    }

    /**
     * Refuse to be serialised with the token inside: a queued job or cache entry
     * holding a client must not carry the credential into storage.
     */
    public function __serialize(): array
    {
        throw new \LogicException('Credentials cannot be serialised');
    }

    /**
     * Companions to {@see Credentials::__serialize()}.
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Credentials cannot be unserialised');
    }
}