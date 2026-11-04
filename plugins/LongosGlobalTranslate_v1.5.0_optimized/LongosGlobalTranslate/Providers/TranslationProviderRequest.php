<?php

namespace Plugin\LongosGlobalTranslate\Providers;

final readonly class TranslationProviderRequest
{
    /** @param list<string> $segments */
    public function __construct(
        public string $requestUuid,
        public int $providerAccountId,
        public string $sourceLocale,
        public string $targetLocale,
        public array $segments,
        public string $contentHash,
        public int $timeoutSeconds = 12,
    ) {}
}
