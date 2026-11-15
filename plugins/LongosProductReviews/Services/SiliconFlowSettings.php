<?php

namespace Plugin\LongosProductReviews\Services;

use Beike\Repositories\SettingRepo;
use Illuminate\Support\Facades\Crypt;
use Plugin\LongosProductReviews\Exceptions\SiliconFlowException;

class SiliconFlowSettings
{
    public const DEFAULT_MODEL = 'XingChenAGI/Xing4.0-29B';

    public const MAX_MODELS = 6;

    private const CODE = 'longos_product_reviews';

    private const ENCRYPTED_PREFIX = 'encrypted:v1:';

    /** @return array{api_key:string,model:string,models:list<string>,timeout:int} */
    public function runtime(): array
    {
        $model  = $this->normalizeModel((string) plugin_setting(self::CODE . '.siliconflow_model', self::DEFAULT_MODEL));
        $models = self::parseModels($model, (string) plugin_setting(self::CODE . '.siliconflow_models', ''));

        return [
            'api_key' => $this->decrypt((string) plugin_setting(self::CODE . '.siliconflow_api_key', '')),
            'model'   => $models[0],
            'models'  => $models,
            'timeout' => max(10, min(120, (int) plugin_setting(self::CODE . '.siliconflow_timeout', 60))),
        ];
    }

    /** @return array{configured:bool,model:string,models:list<string>,parallel_models:string,timeout:int} */
    public function public(): array
    {
        try {
            $runtime = $this->runtime();

            return [
                'configured'      => $runtime['api_key'] !== '',
                'model'           => $runtime['model'],
                'models'          => $runtime['models'],
                'parallel_models' => implode("\n", array_slice($runtime['models'], 1)),
                'timeout'         => $runtime['timeout'],
            ];
        } catch (SiliconFlowException) {
            $model  = $this->normalizeModel((string) plugin_setting(self::CODE . '.siliconflow_model', self::DEFAULT_MODEL));
            $models = self::parseModels($model, (string) plugin_setting(self::CODE . '.siliconflow_models', ''));

            return [
                'configured'      => false,
                'model'           => $models[0],
                'models'          => $models,
                'parallel_models' => implode("\n", array_slice($models, 1)),
                'timeout'         => max(10, min(120, (int) plugin_setting(self::CODE . '.siliconflow_timeout', 60))),
            ];
        }
    }

    /** @param array{api_key?:mixed,model?:mixed,parallel_models?:mixed,timeout?:mixed} $incoming */
    public function save(array $incoming): void
    {
        $apiKey = trim((string) ($incoming['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = $this->runtime()['api_key'];
        }
        if ($apiKey === '') {
            throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_api_key_required'));
        }

        $model  = $this->normalizeModel((string) ($incoming['model'] ?? self::DEFAULT_MODEL));
        $models = self::parseModels($model, (string) ($incoming['parallel_models'] ?? ''));

        SettingRepo::update('plugin', self::CODE, [
            'siliconflow_api_key' => $this->encrypt($apiKey),
            'siliconflow_model'   => $models[0],
            'siliconflow_models'  => implode("\n", $models),
            'siliconflow_timeout' => max(10, min(120, (int) ($incoming['timeout'] ?? 60))),
        ]);
    }

    /** @return list<string> */
    public static function parseModels(string $primary, string $additional = ''): array
    {
        $values = preg_split('/[\s,;，；]+/u', trim($primary . "\n" . $additional)) ?: [];
        $models = [];
        foreach ($values as $value) {
            $model = trim($value);
            if ($model === '' || preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+){1,2}$/', $model) !== 1) {
                continue;
            }
            $models[$model] = true;
            if (count($models) >= self::MAX_MODELS) {
                break;
            }
        }

        return array_keys($models ?: [self::DEFAULT_MODEL => true]);
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
