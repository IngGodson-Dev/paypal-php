<?php

declare(strict_types=1);

namespace Goddy\PayPal\Exception;

final class ApiException extends PayPalException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public int $statusCode,
        public array $details = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
