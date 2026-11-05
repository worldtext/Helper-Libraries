<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getDetails()}.
 */
final class AccountDetails extends Model
{
    /** The account the credential belongs to. */
    public readonly ?string $accountid;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->accountid = self::field($raw, 'accountid');
    }
}