<?php

namespace Plugin\LongosProductReviews\Services;

use Beike\Repositories\SettingRepo;
use Illuminate\Support\Facades\Crypt;
use Plugin\LongosProductReviews\Exceptions\SiliconFlowException;

class SiliconFlowSettings
{
    public const DEFAULT_MODEL = 'XingChenAGI/Xing4.0-29B';

    private const CODE = 'longos_product_reviews';

    private const ENCRYPTED_PREFIX = 'encrypted:v1:';

    /** @return array{api_key:string,model:string,timeout:int} */
    public function runtime(): array
    {
        return [
            'api_key' => $this->decrypt((string) plugin_setting(self::CODE . '.siliconflow_api_key', '')),
            'model'   => $this->normalizeModel((string) plugin_setting(self::CODE . '.siliconflow_model', self::DEFAULT_MODEL)),
            'timeout' => max(10, min(120, (int) plugin_setting(self::CODE . '.siliconflow_timeout', 60))),
        ];
    }

    /** @return array{configured:bool,model:string,timeout:int} */
    public function public(): array
    {
        try {
            $runtime = $this->runtime();

            return [
                'configured' => $runtime['api_key'] !== '',
                'model'      => $runtime['model'],
                'timeout'    => $runtime['timeout'],
            ];
        } catch (SiliconFlowException) {
            return [
                'configured' => false,
                'model'      => $this->normalizeModel((string) plugin_setting(self::CODE . '.siliconflow_model', self::DEFAULT_MODEL)),
                'timeout'    => max(10, min(120, (int) plugin_setting(self::CODE . '.siliconflow_timeout', 60))),
            ];
        }
    }

    /** @param array{api_key?:mixed,model?:mixed,timeout?:mixed} $incoming */
    public function save(array $incoming): void
    {
        $apiKey = trim((string) ($incoming['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = $this->runtime()['api_key'];
        }
        if ($apiKey === '') {
            throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_api_key_required'));
        }

        SettingRepo::update('plugin', self::CODE, [
            'siliconflow_api_key' => $this->encrypt($apiKey),
            'siliconflow_model'   => $this->normalizeModel((string) ($incoming['model'] ?? self::DEFAULT_MODEL)),
            'siliconflow_timeout' => max(10, min(120, (int) ($incoming['timeout'] ?? 60))),
        ]);
    }

    private function normalizeModel(string $model): string
    {
        $model = trim($model);

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    private function encrypt(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return self::ENCRYPTED_PREFIX . Crypt::encryptString($value);
    }

    private function decrypt(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX))));
        } catch (\Throwable $exception) {
            throw new SiliconFlowException(
                trans('LongosProductReviews::reviews.ai_api_key_decrypt_failed'),
                previous: $exception,
            );
        }
    }
}
