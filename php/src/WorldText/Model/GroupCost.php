<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getGroupCost()}.
 *
 * `credits` is the string the gateway sent, e.g. "2.0".
 */
final class GroupCost extends Model
{
    public readonly ?string $credits;

    /** Number of entries the send would cover, as a string. */
    public readonly ?string $entrycount;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->credits = self::field($raw, 'credits');
        $this->entrycount = self::field($raw, 'entrycount', 'entries');
    }

    /**
     * Entry count, as an integer, or null.
     */
    public function entryCountValue(): ?int
    {
        return $this->entrycount === null || !ctype_digit($this->entrycount)
            ? null
            : (int) $this->entrycount;
    }
}