<?php

declare(strict_types=1);

namespace WorldText\Transport;

/**
 * A failure below HTTP: DNS, connection refused, timeout, a TLS error.
 *
 * The client turns this into a retryable `WorldText\Exception\WorldTextException`;
 * it is separate only because transports need an error type that does not depend
 * on the API-facing exception hierarchy.
 */
final class TransportException extends \RuntimeException
{
}