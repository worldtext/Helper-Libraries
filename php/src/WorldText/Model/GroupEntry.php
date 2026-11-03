<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * One number in a group with its delivery status, from
 * {@see \WorldText\WorldText::getGroupDetails()}.
 */
final class GroupEntry extends Model
{
    public readonly ?string $number;

    /** Delivery status of the last send to this number, as sent. */
    public readonly ?string $status;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->number = self::field($raw, 'number');
        $this->status = self::field($raw, 'status');
    }
}