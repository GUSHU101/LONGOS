<?php

namespace App\Saas\Support;

final readonly class IdempotencyResult
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public int $status,
        public bool $replayed = false,
    ) {}

    public function asReplay(): self
    {
        return new self($this->body, $this->status, true);
    }
}
