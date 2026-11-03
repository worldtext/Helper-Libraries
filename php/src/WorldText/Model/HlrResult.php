<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::lookupHlr()}.
 */
final class HlrResult extends Model
{
    public readonly ?string $docomo;
    public readonly ?string $region;
    public readonly ?string $info;
    public readonly ?string $error;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->docomo = self::field($raw, 'docomo');
        $this->region = self::field($raw, 'region');
        $this->info = self::field($raw, 'info');
        $this->error = self::field($raw, 'error');
    }

    /**
     * Whether the lookup itself succeeded (no HLR error code).
     */
    public function failed(): bool
    {
        return $this->error !== null && $this->error !== '0';
    }
}