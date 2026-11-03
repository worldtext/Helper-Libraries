<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getNumberInfo()}: country and network.
 */
final class NumberInfo extends Model
{
    /** Country calling code, as a string, e.g. "44". */
    public readonly ?string $cc;

    /** Mobile country code, as a string, e.g. "234". */
    public readonly ?string $mcc;

    /** Mobile network code, as a string, e.g. "10". */
    public readonly ?string $mnc;

    public readonly ?string $country;
    public readonly ?string $network;

    /** Credits charged for the lookup, as a string. */
    public readonly ?string $credits;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->cc = self::field($raw, 'cc');
        $this->mcc = self::field($raw, 'mcc');
        $this->mnc = self::field($raw, 'mnc');
        $this->country = self::field($raw, 'country');
        $this->network = self::field($raw, 'network');
        $this->credits = self::field($raw, 'credits');
    }

    /**
     * Credits charged, as an exact decimal string.
     */
    public function creditsAsDecimal(): ?string
    {
        return self::decimal($this->credits);
    }
}