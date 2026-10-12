<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Api;

final class DokAuditException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $errorCode = '')
    {
        parent::__construct($message, $status);
    }
}
