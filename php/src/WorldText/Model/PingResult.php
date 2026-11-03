<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::ping()}.
 */
final class PingResult extends Model
{
    /** Whether the gateway reported success (`status` == "0"). */
    public readonly bool $ok;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->ok = self::field($raw, 'status') === StatusFlag::SUCCESS;
    }

    /**
     * The gateway's `status` flag, as sent.
     */
    public function status(): ?string
    {
        return self::field($this->raw, 'status');
    }
}