<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::createGroup()}.
 *
 * The id is accepted as `grpid` or `groupid`.
 */
final class CreateGroupResult extends Model
{
    public readonly ?string $grpid;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->grpid = self::field($raw, 'grpid', 'groupid', 'id');
    }

    /**
     * The new group's id.
     */
    public function groupId(): ?string
    {
        return $this->grpid;
    }
}