<?php

declare(strict_types=1);

namespace WorldText\Exception;

/**
 * 401: the token is missing, malformed, unknown or revoked.
 *
 * Permanent: retrying with the same credential cannot help.
 */
final class AuthenticationException extends WorldTextException
{
}