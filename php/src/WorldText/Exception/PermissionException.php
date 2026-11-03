<?php

declare(strict_types=1);

namespace WorldText\Exception;

/**
 * 403: the token is valid but lacks the permission this operation needs.
 *
 * {@see PermissionException::getRequiredScope()} names the permission to add to
 * the token: 'sms', 'groups', 'admin' or 'hlr'.
 */
final class PermissionException extends WorldTextException
{
    /**
     * @param string|null $requiredScope Permission the operation needs, taken
     *                                   from the WWW-Authenticate header.
     */
    public function __construct(
        string $message,
        ?int $httpStatus = 403,
        ?string $errorCode = null,
        ?string $desc = null,
        string $statusFlag = self::STATUS_PERMANENT,
        private readonly ?string $requiredScope = null,
    ) {
        parent::__construct($message, $httpStatus, $errorCode, $desc, $statusFlag);
    }

    /**
     * The scope the operation requires, e.g. 'admin', or null when unknown.
     */
    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }

    public function __toString(): string
    {
        $scope = $this->requiredScope === null ? 'null' : "'" . $this->requiredScope . "'";

        return rtrim(parent::__toString(), ')') . ', requiredScope=' . $scope . ')';
    }
}