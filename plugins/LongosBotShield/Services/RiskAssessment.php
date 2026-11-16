<?php

namespace Plugin\LongosBotShield\Services;

class RiskAssessment
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly int $score,
        public readonly array $reasons,
        public readonly bool $allowlisted = false,
        public readonly bool $verifiedCrawler = false,
    ) {}

    public function hasReason(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }
}
