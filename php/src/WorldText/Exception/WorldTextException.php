<?php

declare(strict_types=1);

namespace WorldText\Exception;

use WorldText\Token;

/**
 * Base class for every failure raised by this client.
 *
 * Callers branch on the type (or on {@see WorldTextException::isRetryable()}),
 * never on message text. Each error carries the HTTP status and, when the
 * gateway supplied one, the numeric API error code from the JSON envelope.
 *
 * The token is never stored here, so errors are safe to log.
 */
class WorldTextException extends \RuntimeException
{
    /** Envelope `status` for a permanent failure. */
    public const STATUS_PERMANENT = '1';

    /**
     * @param string      $message    Human-readable description; never contains
     *                                the token.
     * @param int|null    $httpStatus HTTP status, or null for a transport failure.
     * @param string|null $errorCode  Gateway error code, e.g. '1200', or null.
     * @param string|null $desc       Gateway description, or the transport error.
     * @param string      $statusFlag Envelope `status`: '1' permanent, '2'/'3'
     *                                temporary.
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $desc = null,
        private readonly string $statusFlag = self::STATUS_PERMANENT,
    ) {
        parent::__construct(Token::scrub($message));
    }

    /**
     * HTTP status returned, or null when no response arrived.
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * The gateway's error code as a string, e.g. '1200', or null.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The gateway's description, or the transport error for a network failure.
     */
    public function getDesc(): ?string
    {
        return $this->desc;
    }

    /**
     * The envelope `status` flag that accompanied the failure.
     */
    public function getStatusFlag(): string
    {
        return $this->statusFlag;
    }

    /**
     * True when the gateway said the failure is temporary.
     *
     * The envelope's `status` of '2' or '3' means "may be retried"; '1' is
     * permanent. A 5xx from a gateway or proxy, and a failure to reach the
     * gateway at all, are also treated as retryable.
     */
    public function isRetryable(): bool
    {
        if ($this->statusFlag === '2' || $this->statusFlag === '3') {
            return true;
        }

        // No status means no HTTP exchange: a transient network problem.
        return $this->httpStatus === null || $this->httpStatus >= 500;
    }

    /**
     * A short diagnostic form: type, status and code. Never the token.
     */
    public function __toString(): string
    {
        return sprintf(
            '%s(httpStatus=%s, errorCode=%s, message=%s)',
            (new \ReflectionClass($this))->getShortName(),
            $this->httpStatus === null ? 'null' : (string) $this->httpStatus,
            $this->errorCode === null ? 'null' : "'" . $this->errorCode . "'",
            $this->getMessage(),
        );
    }
}