<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::querySmsStatus()}.
 */
final class SmsStatus extends Model
{
    /** Delivery state, as a string. */
    public readonly ?string $state;

    /** Error code for the delivery, as a string. */
    public readonly ?string $error;

    /** Final delivery time, or null while still in flight. */
    public readonly ?string $finaldate;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->state = self::field($raw, 'state');
        $this->error = self::field($raw, 'error');
        $this->finaldate = self::field($raw, 'finaldate');
    }

    /**
     * False while the message is still in flight.
     */
    public function isFinal(): bool
    {
        return $this->finaldate !== null && $this->finaldate !== '';
    }
}