<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::lookupMnp()}: porting status.
 */
final class MnpResult extends Model
{
    /** e.g. "ported" or "not ported". */
    public readonly ?string $mnpportstatus;

    /** e.g. "2020-01-01", or null when the number has not been ported. */
    public readonly ?string $mnpportdate;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->mnpportstatus = self::field($raw, 'mnpportstatus');
        $this->mnpportdate = self::field($raw, 'mnpportdate');
    }

    /**
     * Whether the number has been ported from its original network.
     */
    public function isPorted(): bool
    {
        return $this->mnpportstatus !== null && str_contains(strtolower($this->mnpportstatus), 'ported')
            && !str_starts_with(strtolower($this->mnpportstatus), 'not');
    }
}