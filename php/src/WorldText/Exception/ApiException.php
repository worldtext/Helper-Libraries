<?php

declare(strict_types=1);

namespace WorldText\Exception;

/**
 * Any other error response: validation (400, code 1200), insufficient funds,
 * unknown command, or a 5xx from the gateway or a proxy.
 */
final class ApiException extends WorldTextException
{
}