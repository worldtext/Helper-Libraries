<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getCredits()}.
 *
 * `credits` is the string the gateway sent, e.g. `"2950"`; use
 * {@see Model::asDecimal()} for arithmetic.
 */
final class Credits extends Model
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