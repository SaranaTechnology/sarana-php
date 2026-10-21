<?php

declare(strict_types=1);

namespace Sarana;

/** Thrown for every non-2xx response. Status 0 = network error. */
final class SaranaException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly string $body,
    ) {
        parent::__construct('Sarana HTTP ' . $status . ': ' . $message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
