<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::listGroups()}.
 *
 * The envelope wraps the list under `group`; a single object is normalised to
 * a one-element list.
 */
final class GroupList extends Model
{
    /** @var list<Group> */
    public readonly array $groups;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);

        $items = $raw['group'] ?? $raw['groups'] ?? [];
        if (is_array($items) && !array_is_list($items)) {
            $items = [$items];
        }

        $groups = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $groups[] = new Group($item);
                }
            }
        }

        $this->groups = $groups;
    }

    /**
     * How many groups the account has.
     */
    public function count(): int
    {
        return count($this->groups);
    }
}