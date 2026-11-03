<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Result of {@see \WorldText\WorldText::sendSms()} and `sendGroupSms()`.
 *
 * A send returns one {@see SentMessage} per destination, under `result`.
 */
final class SendResult extends Model
{
    /** @var list<SentMessage> */
    public readonly array $messages;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(array $raw)
    {
        parent::__construct($raw);

        $items = $raw['result'] ?? [];
        if (is_array($items) && !array_is_list($items)) {
            // A single result object rather than a list of one.
            $items = [$items];
        }

        $messages = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $messages[] = new SentMessage($item);
                }
            }
        }

        $this->messages = $messages;
    }

    /**
     * The first message id, or null. The common single-recipient case.
     */
    public function msgid(): ?string
    {
        return $this->messages === [] ? null : $this->messages[0]->msgid;
    }

    /**
     * The first message, or null.
     */
    public function first(): ?SentMessage
    {
        return $this->messages[0] ?? null;
    }
}