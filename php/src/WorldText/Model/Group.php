<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * One group in a {@see GroupList}.
 *
 * The gateway names the id `grpid` on some routes and `groupid` on others;
 * both are accepted here (and so is a bare `id`), so callers see one field.
 */
final class Group extends Model
{
    public readonly ?string $grpid;
    public readonly ?string $name;

    /** Number of entries in the group, as a string. */
    public readonly ?string $size;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->grpid = self::field($raw, 'grpid', 'groupid', 'id');
        $this->name = self::field($raw, 'name');
        $this->size = self::field($raw, 'size', 'entries');
    }

    /**
     * Entry count, as an integer (a count is genuinely arithmetic).
     */
    public function sizeValue(): ?int
    {
        return $this->size === null || !ctype_digit($this->size) ? null : (int) $this->size;
    }
}