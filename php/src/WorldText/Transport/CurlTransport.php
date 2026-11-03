<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * curl extension transport. Preferred when PHP was built with ext-curl.
 */
final class CurlTransport implements Transport
{
    private const MAX_REDIRECTS = 5;

    /** @var array<int, mixed> */
    private array $responseHeaders = [];

    /**
     * @param string $userAgent Value for the User-Agent header.
     */
    public function __construct(private readonly string $userAgent)
    {
    }

    /**
     * Whether this transport can run in the current PHP build.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('curl') && function_exists('curl_init');
    }

    public function name(): string
    {
        return 'curl';
    }

    public function send(Request $request, float $timeout): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('could not initialise curl');
        }

        $this->responseHeaders = [];

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADERFUNCTION => $this->collectHeader(...),
        ];

        if ($request->body !== null && $request->body !== '') {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);

        try {
            $body = curl_exec($handle);
            if ($body === false) {
                throw new TransportException((string) curl_error($handle));
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        return new Response($status, $this->normalizedHeaders(), (string) $body);
    }

    /**
     * Collects the headers of the *final* response only: on a redirect curl
     * reports each response in turn, and a blank line marks the end of one.
     */
    private function collectHeader(mixed $handle, string $line): int
    {
        $length = strlen($line);
        $trimmed = trim($line);

        if (str_starts_with($trimmed, 'HTTP/')) {
            // A new status line: everything gathered so far belonged to an
            // earlier hop of the redirect chain.
            $this->responseHeaders = [];

            return $length;
        }

        if ($trimmed === '') {
            return $length;
        }

        $this->responseHeaders[] = $trimmed;

        return $length;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedHeaders(): array
    {
        $headers = [];
        foreach ($this->responseHeaders as $line) {
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $position)));
            $headers[$name] = trim(substr($line, $position + 1));
        }

        return $headers;
    }
}