<?php

namespace Plugin\LongosGlobalTranslate\Services;

final readonly class PresentationLocaleDecision
{
    public function __construct(
        public string $locale,
        public string $source,
        public ?string $country,
        public bool $fallback,
    ) {}

    /** @return array{locale:string,source:string,country:?string,fallback:bool} */
    public function toArray(): array
    {
        return ['locale' => $this->locale, 'source' => $this->source, 'country' => $this->country, 'fallback' => $this->fallback];
    }
}
