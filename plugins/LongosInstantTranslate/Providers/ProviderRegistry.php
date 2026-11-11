<?php

namespace Plugin\LongosInstantTranslate\Providers;

use InvalidArgumentException;

final class ProviderRegistry
{
    /** @param array<string, TranslationProviderInterface> $overrides */
    public function __construct(private readonly array $overrides = []) {}

    public function get(string $provider): TranslationProviderInterface
    {
        if (isset($this->overrides[$provider])) {
            return $this->overrides[$provider];
        }

        return match ($provider) {
            'aliyun'      => new AliyunProvider,
            'volcengine'  => new VolcengineProvider,
            'baidu'       => new BaiduProvider,
            'deepl'       => new DeepLProvider,
            'google'      => new GoogleCloudProvider,
            'azure'       => new AzureTranslatorProvider,
            'siliconflow' => new SiliconFlowProvider,
            default       => throw new InvalidArgumentException('UNKNOWN_PROVIDER'),
        };
    }
}
