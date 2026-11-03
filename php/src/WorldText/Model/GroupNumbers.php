<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getGroupNumbers()}.
 *
 * Numbers are strings. The payload may hold bare strings or objects with a
 * `number` field; both are flattened to a list of strings.
 */
final class GroupNumbers extends Model
{
    /** @var list<string> */
    public readonly array $numbers;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);

        $items = $raw['numbers'] ?? $raw['entry'] ?? [];
        $numbers = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $number = is_array($item) ? self::field($item, 'number') : self::text($item);
                if ($number !== null) {
                    $numbers[] = $number;
                }
            }
        }

        $this->numbers = $numbers;
    }

    /**
     * How many numbers the group holds.
     */
    public function count(): int
    {
        return count($this->numbers);
    }

    /**
     * Whether the group contains `$number`.
     */
    public function contains(string $number): bool
    {
        return in_array($number, $this->numbers, true);
    }
}