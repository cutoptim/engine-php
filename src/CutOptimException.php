<?php

declare(strict_types=1);

namespace CutOptim\Engine;

/** A non-2xx answer. getErrorCode() is the stable error (invalid_request, quota_exceeded, …) — branch on it. */
final class CutOptimException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly mixed $details = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int { return $this->status; }
    public function getErrorCode(): string { return $this->errorCode; }
    public function getDetails(): mixed { return $this->details; }
    public function getRetryAfter(): ?int { return $this->retryAfter; }
}
