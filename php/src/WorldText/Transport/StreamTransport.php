<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * Fallback transport built on the core stream wrapper, for PHP builds without
 * ext-curl (no Composer packages are required either).
 *
 * It needs `allow_url_fopen` to be on. Every response, including 4xx and 5xx,
 * is returned rather than raised; the stream's own warnings are captured and
 * turned into a {@see TransportException} only when no response arrived.
 */
final class StreamTransport implements Transport
{
    private const MAX_REDIRECTS = 5;

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
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
    }

    public function name(): string
    {
        return 'stream';
    }

    public function send(Request $request, float $timeout): Response
    {
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'User-Agent: ' . $this->userAgent;

        $http = [
            'method' => $request->method,
            'header' => implode("\r\n", $lines),
            'timeout' => $timeout,
            'ignore_errors' => true,   // keep the body of 4xx/5xx responses
            'follow_location' => 1,
            'max_redirects' => self::MAX_REDIRECTS,
            'protocol_version' => 1.1,
        ];
        if ($request->body !== null && $request->body !== '') {
            $http['content'] = $request->body;
        }

        $context = stream_context_create(['http' => $http]);

        $failure = null;
        set_error_handler(static function (int $severity, string $message) use (&$failure): bool {
            // fopen() warns once per failure; keep the reason and carry on.
            $failure = $message;

            return true;
        });

        try {
            $stream = fopen($request->url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new TransportException(self::reason($failure));
        }

        try {
            $body = stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }

        if ($body === false) {
            throw new TransportException('could not read the response body');
        }

        $headers = [];
        $status = 0;
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $match) === 1) {
                // The wrapper reports each redirect hop; keep the last one.
                $status = (int) $match[1];
                $headers = [];
                continue;
            }
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $position)));
            $headers[$name] = trim(substr($line, $position + 1));
        }

        if ($status === 0) {
            // A reply with no status line is not a usable HTTP response.
            throw new TransportException('malformed HTTP response');
        }

        return new Response($status, $headers, $body);
    }

    /**
     * Turn PHP's `fopen(http://...)` warning into a short, URL-free reason.
     *
     * The warning repeats the URL; only the part after the closing parenthesis
     * is useful, and it never contains the token (which travels in a header).
     */
    private static function reason(?string $warning): string
    {
        if ($warning === null || $warning === '') {
            return 'request failed';
        }

        $position = strrpos($warning, '): ');
        $reason = $position === false ? $warning : substr($warning, $position + 3);

        return $reason === '' ? 'request failed' : $reason;
    }
}