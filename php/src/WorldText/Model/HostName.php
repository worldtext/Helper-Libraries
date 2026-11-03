<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::getHost()}.
 */
final class HostName extends Model
{
    /** Name of the host that served the request. */
    public readonly ?string $hostname;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->hostname = self::field($raw, 'hostname');
    }
}