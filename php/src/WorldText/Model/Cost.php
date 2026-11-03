<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getSmsCost()}.
 *
 * `credits` is a string, e.g. "1.0"; {@see Model::asDecimal()} returns "1".
 */
final class Cost extends Model
{
    public readonly ?string $credits;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->credits = self::field($raw, 'credits');
    }
}