<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of the delete operations, which return nothing beyond `status`; the
 * count of removed rows is reported when the gateway includes it.
 */
final class DeleteResult extends Model
{
    /** Entries or groups removed, as a string, or null when not reported. */
    public readonly ?string $deleted;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->deleted = self::field($raw, 'deleted', 'rows');
    }

    /**
     * Entries removed, as an integer, or null when not reported.
     */
    public function deletedValue(): ?int
    {
        return $this->deleted === null || !ctype_digit($this->deleted) ? null : (int) $this->deleted;
    }
}