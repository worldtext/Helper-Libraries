<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * Chooses a transport for the current PHP build.
 *
 * ext-curl is preferred; when it is not compiled in, the streams transport is
 * used and the caller need not care.
 */
final class TransportFactory
{
    /**
     * The best transport this PHP build can run.
     *
     * @throws \RuntimeException when neither curl nor the stream wrapper exists.
     */
    public static function create(string $userAgent): Transport
    {
        if (CurlTransport::isAvailable()) {
            return new CurlTransport($userAgent);
        }

        if (StreamTransport::isAvailable()) {
            return new StreamTransport($userAgent);
        }

        throw new \RuntimeException(
            'no HTTP transport available: enable ext-curl or allow_url_fopen',
        );
    }

    /**
     * Whether anything can perform an HTTP request in this build.
     */
    public static function isAvailable(): bool
    {
        return CurlTransport::isAvailable() || StreamTransport::isAvailable();
    }

    private function __construct()
    {
    }
}