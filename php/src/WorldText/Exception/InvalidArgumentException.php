<?php

declare(strict_types=1);

namespace WorldText\Exception;

/**
 * A request the client will not send: a required argument was missing, or an
 * argument had the wrong type. No HTTP exchange happened.
 */
final class InvalidArgumentException extends WorldTextException
{
}