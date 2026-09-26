<?php

declare(strict_types=1);

namespace NotionDrive;

use RuntimeException;

final class NotionApiException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }
}
