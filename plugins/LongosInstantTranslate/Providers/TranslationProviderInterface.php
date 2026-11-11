<?php

namespace Plugin\LongosInstantTranslate\Providers;

interface TranslationProviderInterface
{
    public function id(): string;

    /** @param array<string, string> $credentials */
    public function validateCredentialShape(array $credentials): void;

    public function supports(string $sourceLocale, string $targetLocale): bool;

    /** @param list<string> $segments @return list<list<string>> */
    public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array;

    /** @param array<string, string> $credentials */
    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse;

    /** @return array{kind:string,code:string,definitely_unbilled:bool} */
    public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array;
}
