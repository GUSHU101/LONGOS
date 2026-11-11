<?php

namespace Plugin\LongosInstantTranslate\Providers;

final readonly class TranslationProviderRequest
{
    /**
     * @param list<string>                                                                       $segments
     * @param array{kind:string,field:string,domain:string,prompt:string,profile_version:string} $context
     */
    public function __construct(
        public string $requestUuid,
        public int $providerAccountId,
        public string $sourceLocale,
        public string $targetLocale,
        public array $segments,
        public string $contentHash,
        public int $timeoutSeconds = 12,
        public array $context = [],
    ) {}
}
