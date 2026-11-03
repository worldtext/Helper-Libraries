<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getGroupDetails()}: numbers with status.
 */
final class GroupDetails extends Model
{
    /** @var list<GroupEntry> */
    public readonly array $entries;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);

        $items = $raw['details'] ?? $raw['entry'] ?? [];
        $entries = [];
        if (is_array($items)) {
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $entries[] = new GroupEntry($item);
                } elseif ($item !== null) {
                    // A bare number: keep it, with no status.
                    $entries[] = new GroupEntry(['number' => is_string($key) ? $key : $item]);
                }
            }
        }

        $this->entries = $entries;
    }

    /**
     * How many entries the group holds.
     */
    public function count(): int
    {
        return count($this->entries);
    }
}