<?php

namespace Plugin\LongosGlobalTranslate\Providers;

final readonly class TranslationProviderResponse
{
    /** @param list<string> $translations */
    public function __construct(
        public bool $ok,
        public array $translations,
        public ?string $requestId,
        public ?string $errorCode,
        public string $rawResponseHash,
        public int $roundTripMs,
        public ?int $totalTokens = null,
        public ?int $retryAfterSeconds = null,
    ) {}
}
