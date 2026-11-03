<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * One destination's outcome inside a {@see SendResult}.
 */
final class SentMessage extends Model
{
    /** Message id, as a string, e.g. "10001". */
    public readonly ?string $msgid;

    /** Destination address, when the gateway echoes it. */
    public readonly ?string $dstaddr;

    /** Credits charged, as a string. */
    public readonly ?string $credits;

    /** Envelope status flag, as a string, e.g. "5". */
    public readonly ?string $status;

    /** Send time, as the gateway formatted it. */
    public readonly ?string $sendtime;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->msgid = self::field($raw, 'msgid');
        $this->dstaddr = self::field($raw, 'dstaddr');
        $this->credits = self::field($raw, 'credits');
        $this->status = self::field($raw, 'status');
        $this->sendtime = self::field($raw, 'sendtime');
    }

    /**
     * Credits charged for this destination, as an exact decimal string.
     */
    public function creditsAsDecimal(): ?string
    {
        return self::decimal($this->credits);
    }
}