<?php

declare(strict_types=1);

namespace Sarana;

/** Thrown for every non-2xx response. Status 0 = network error. */
final class SaranaException extends \RuntimeException
{
    private int $status;
    private string $body;

    public function __construct(int $status, string $message, string $body)
    {
        parent::__construct('Sarana HTTP ' . $status . ': ' . $message, $status);
        $this->status = $status;
        $this->body = $body;
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
