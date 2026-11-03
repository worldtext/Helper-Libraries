<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of the group entry-add operations.
 *
 * `added` and `failed` are counts held as the strings the gateway sent.
 */
final class AddEntriesResult extends Model
{
    public readonly ?string $added;
    public readonly ?string $failed;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->added = self::field($raw, 'added');
        $this->failed = self::field($raw, 'failed');
    }

    /**
     * Number of entries added, as an integer (a count is genuinely arithmetic).
     */
    public function addedValue(): ?int
    {
        return self::asCount($this->added);
    }

    /**
     * Number of entries rejected, as an integer.
     */
    public function failedValue(): ?int
    {
        return self::asCount($this->failed);
    }

    private static function asCount(?string $count): ?int
    {
        return $count === null || !ctype_digit($count) ? null : (int) $count;
    }
}