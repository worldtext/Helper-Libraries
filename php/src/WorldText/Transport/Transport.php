<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * The minimal HTTP contract the client needs.
 *
 * Implementations must return every HTTP status, including 4xx and 5xx, rather
 * than raising: the gateway sends a JSON error envelope with each one and the
 * client interprets it. They raise {@see TransportException} only when no HTTP
 * exchange happened at all.
 */
interface Transport
{
    /**
     * Short name of the backing mechanism, e.g. "curl" - useful in diagnostics.
     */
    public function name(): string;

    /**
     * @throws TransportException when the request could not be performed.
     */
    public function send(Request $request, float $timeout): Response;
}