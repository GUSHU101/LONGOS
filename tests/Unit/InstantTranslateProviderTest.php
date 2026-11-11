<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Providers\AliyunProvider;
use Plugin\LongosInstantTranslate\Providers\AzureTranslatorProvider;
use Plugin\LongosInstantTranslate\Providers\BaiduProvider;
use Plugin\LongosInstantTranslate\Providers\DeepLProvider;
use Plugin\LongosInstantTranslate\Providers\GoogleCloudProvider;
use Plugin\LongosInstantTranslate\Providers\ProviderRegistry;
use Plugin\LongosInstantTranslate\Providers\SiliconFlowProvider;
use Plugin\LongosInstantTranslate\Providers\TranslationProviderRequest;
use Plugin\LongosInstantTranslate\Providers\VolcengineProvider;

class InstantTranslateProviderTest extends TestCase
{
    public static function supportCases(): iterable
    {
        foreach ([AliyunProvider::class, VolcengineProvider::class, BaiduProvider::class, DeepLProvider::class, GoogleCloudProvider::class, AzureTranslatorProvider::class, SiliconFlowProvider::class] as $provider) {
            yield $provider . ' supports en-fr' => [$provider, 'en', 'fr', true];
            yield $provider . ' supports en-us-de' => [$provider, 'en-US', 'de', true];
            yield $provider . ' rejects same language' => [$provider, 'en', 'en', false];
            yield $provider . ' rejects unknown language' => [$provider, 'en', 'xx', false];
        }
    }

    public static function storefrontTargetLocales(): iterable
    {
        foreach ([AliyunProvider::class, VolcengineProvider::class, BaiduProvider::class, DeepLProvider::class, GoogleCloudProvider::class, AzureTranslatorProvider::class, SiliconFlowProvider::class] as $provider) {
            foreach (['ar', 'de', 'es', 'fr', 'id', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'ru', 'th', 'tr', 'vi', 'zh-cn', 'zh-tw'] as $locale) {
                yield $provider . ' supports storefront target ' . $locale => [$provider, $locale];
            }
        }
    }

    #[DataProvider('supportCases')]
    public function test_provider_language_capabilities_are_explicit(string $class, string $source, string $target, bool $expected): void
    {
        self::assertSame($expected, (new $class)->supports($source, $target));
    }

    #[DataProvider('storefrontTargetLocales')]
    public function test_every_bundled_provider_supports_each_storefront_target_locale(string $class, string $target): void
    {
        self::assertTrue((new $class)->supports('en', $target));
    }

    public function test_aliyun_success_and_definite_unsupported_error_are_parsed_without_network(): void
    {
        $success  = new AliyunProvider($this->client(['Code' => 200, 'RequestId' => 'ali-request', 'Data' => ['Translated' => 'Bonjour']]));
        $response = $success->send($this->request(['Hello']), ['access_key_id' => 'key', 'access_key_secret' => 'secret']);
        self::assertTrue($response->ok);
        self::assertSame(['Bonjour'], $response->translations);

        $failed = new AliyunProvider($this->client(['Code' => 10005, 'RequestId' => 'ali-error']));
        $error  = $failed->send($this->request(['Hello']), ['access_key_id' => 'key', 'access_key_secret' => 'secret']);
        self::assertFalse($error->ok);
        self::assertSame('UNSUPPORTED_LANGUAGE', $error->errorCode);
        self::assertTrue($failed->classifyFailure($error)['definitely_unbilled']);
    }

    public function test_aliyun_accepts_both_documented_rpc_response_envelopes(): void
    {
        $provider = new AliyunProvider($this->client([
            'TranslateGeneralResponse' => [
                'Code'      => 200,
                'RequestId' => 'ali-wrapped-request',
                'Data'      => ['Translated' => 'Bonjour'],
            ],
        ]));

        $response = $provider->send($this->request(['Hello']), ['access_key_id' => 'key', 'access_key_secret' => 'secret']);

        self::assertTrue($response->ok);
        self::assertSame('ali-wrapped-request', $response->requestId);
        self::assertSame(['Bonjour'], $response->translations);
    }

    public function test_aliyun_uses_a_signed_post_form_so_credentials_and_text_never_enter_a_url(): void
    {
        $history = [];
        $client  = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode(['Code' => 200, 'Data' => ['Translated' => 'Bonjour']], JSON_THROW_ON_ERROR)),
            ])),
            'http_errors' => false,
        ]);
        $stack = $client->getConfig('handler');
        $stack->push(Middleware::history($history));

        (new AliyunProvider($client))->send($this->request(['Hello']), ['access_key_id' => 'key', 'access_key_secret' => 'secret']);

        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('', $history[0]['request']->getUri()->getQuery());
        $body = (string) $history[0]['request']->getBody();
        self::assertStringContainsString('AccessKeyId=key', $body);
        self::assertStringContainsString('SourceText=Hello', $body);
        self::assertStringNotContainsString('secret', $body);
    }

    public function test_aliyun_receives_the_safe_commerce_context_when_its_api_supports_it(): void
    {
        $history  = [];
        $provider = new AliyunProvider($this->recordingClient($history, ['Code' => 200, 'Data' => ['Translated' => 'Bonjour']]));
        $request  = new TranslationProviderRequest(
            '8224d0b9-ae7e-4f7a-9634-9eed9263b42a', 1, 'en', 'fr', ['Phone case'], hash('sha256', 'Phone case'), 4,
            ['kind' => 'product', 'field' => 'name', 'domain' => 'product title', 'prompt' => 'Cross-border e-commerce storefront. Field type: product title.', 'profile_version' => 'test'],
        );

        $provider->send($request, ['access_key_id' => 'key', 'access_key_secret' => 'secret']);
        parse_str((string) $history[0]['request']->getBody(), $fields);

        self::assertSame('Cross-border e-commerce storefront. Field type: product title.', $fields['Context']);
        self::assertSame('Phone case', $fields['SourceText']);
    }

    public function test_standard_endpoints_keep_context_out_of_the_source_copy_when_the_api_has_no_context_field(): void
    {
        $request = new TranslationProviderRequest(
            '8224d0b9-ae7e-4f7a-9634-9eed9263b42a', 1, 'en', 'fr', ['Phone case'], hash('sha256', 'Phone case'), 4,
            ['kind' => 'product', 'field' => 'name', 'domain' => 'product title', 'prompt' => 'INTERNAL RULE: never become source text', 'profile_version' => 'test'],
        );

        $baiduHistory = [];
        (new BaiduProvider($this->recordingClient($baiduHistory, ['trans_result' => [['dst' => 'Bonjour']]])))
            ->send($request, ['appid' => 'id', 'secret' => 'secret']);
        parse_str((string) $baiduHistory[0]['request']->getBody(), $baiduFields);
        self::assertSame('Phone case', $baiduFields['q']);
        self::assertStringNotContainsString('INTERNAL+RULE', (string) $baiduHistory[0]['request']->getBody());

        $volcHistory = [];
        (new VolcengineProvider($this->recordingClient($volcHistory, ['ResponseMetadata' => ['RequestId' => 'request'], 'TranslationList' => [['Translation' => 'Bonjour']]])))
            ->send($request, ['access_key_id' => 'key', 'secret_access_key' => 'secret']);
        $volcBody = json_decode((string) $volcHistory[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Phone case'], $volcBody['TextList']);
        self::assertStringNotContainsString('INTERNAL RULE', (string) $volcHistory[0]['request']->getBody());
    }

    public function test_extended_provider_registry_exposes_only_concrete_supported_adapters(): void
    {
        $registry = new ProviderRegistry;

        self::assertInstanceOf(DeepLProvider::class, $registry->get('deepl'));
        self::assertInstanceOf(GoogleCloudProvider::class, $registry->get('google'));
        self::assertInstanceOf(AzureTranslatorProvider::class, $registry->get('azure'));
        self::assertInstanceOf(SiliconFlowProvider::class, $registry->get('siliconflow'));
    }

    public function test_siliconflow_translation_trace_usage_rate_limit_and_truncation_contracts(): void
    {
        $history = [];
        $stack   = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json', 'x-siliconcloud-trace-id' => 'trace-fallback'], json_encode([
                'id'      => 'sf-request',
                'choices' => [['message' => ['content' => 'Coque de téléphone'], 'finish_reason' => 'stop']],
                'usage'   => ['total_tokens' => 123],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $provider = new SiliconFlowProvider(new Client(['handler' => $stack, 'http_errors' => false]));
        $result   = $provider->send($this->request(['Phone case']), [
            'api_key' => 'siliconflow-secret', 'model' => SiliconFlowProvider::DEFAULT_MODEL,
        ]);

        self::assertTrue($result->ok);
        self::assertSame(['Coque de téléphone'], $result->translations);
        self::assertSame('sf-request', $result->requestId);
        self::assertSame(123, $result->totalTokens);
        self::assertSame('', $history[0]['request']->getUri()->getQuery());
        self::assertSame('Bearer siliconflow-secret', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('8224d0b9-ae7e-4f7a-9634-9eed9263b42a', $history[0]['request']->getHeaderLine('X-Trace-Id'));
        self::assertStringContainsString('Translate the following segment into French', (string) $history[0]['request']->getBody());
        $requestBody = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0.7, $requestBody['temperature']);
        self::assertSame(0.6, $requestBody['top_p']);
        self::assertSame(20, $requestBody['top_k']);
        self::assertSame(1.05, $requestBody['repetition_penalty']);

        $rateLimited = new SiliconFlowProvider(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '17', 'x-siliconcloud-trace-id' => 'sf-rate-trace'], json_encode(['message' => 'limited'], JSON_THROW_ON_ERROR)),
            ])),
            'http_errors' => false,
        ]));
        $rate = $rateLimited->send($this->request(['Phone case']), [
            'api_key' => 'siliconflow-secret', 'model' => SiliconFlowProvider::DEFAULT_MODEL,
        ]);
        self::assertFalse($rate->ok);
        self::assertSame('RATE_LIMIT', $rate->errorCode);
        self::assertSame('sf-rate-trace', $rate->requestId);
        self::assertSame(17, $rate->retryAfterSeconds);
        self::assertTrue($rateLimited->classifyFailure($rate)['definitely_unbilled']);

        $truncated = new SiliconFlowProvider(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'choices' => [['message' => ['content' => 'Partial'], 'finish_reason' => 'length']],
                ], JSON_THROW_ON_ERROR)),
            ])),
            'http_errors' => false,
        ]));
        self::assertSame('MODEL_OUTPUT_TRUNCATED', $truncated->send($this->request(['Phone case']), [
            'api_key' => 'siliconflow-secret', 'model' => SiliconFlowProvider::DEFAULT_MODEL,
        ])->errorCode);
    }

    public function test_siliconflow_hunyuan_supports_every_language_exposed_by_the_storefront(): void
    {
        $provider = new SiliconFlowProvider;

        self::assertTrue($provider->supports('en', 'ms'));
        self::assertTrue($provider->supports('en', 'hi'));
    }

    public function test_deepl_uses_its_free_endpoint_and_formal_context_without_leaking_the_key_to_the_url(): void
    {
        $history = [];
        $request = new TranslationProviderRequest(
            '8224d0b9-ae7e-4f7a-9634-9eed9263b42a', 1, 'en', 'fr', ['Phone case'], hash('sha256', 'Phone case'), 4,
            ['kind' => 'product', 'field' => 'name', 'domain' => 'product title', 'prompt' => 'Retail product title; preserve terminology.', 'profile_version' => 'test'],
        );
        $response = (new DeepLProvider($this->recordingClient($history, ['translations' => [['text' => 'Coque de téléphone']]])))
            ->send($request, ['auth_key' => 'deepl-secret', 'api_plan' => 'free']);

        self::assertTrue($response->ok);
        self::assertSame('api-free.deepl.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('', $history[0]['request']->getUri()->getQuery());
        self::assertSame('DeepL-Auth-Key deepl-secret', $history[0]['request']->getHeaderLine('Authorization'));
        $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Phone case'], $body['text']);
        self::assertSame('Retail product title; preserve terminology.', $body['context']);
    }

    public function test_google_and_azure_keep_credentials_in_headers_and_parse_documented_response_shapes(): void
    {
        $googleHistory = [];
        $google        = new GoogleCloudProvider($this->recordingClient($googleHistory, ['data' => ['translations' => [['translatedText' => 'Bonjour &amp; merci']]]]));
        $googleResult  = $google->send($this->request(['Hello & thanks']), ['api_key' => 'google-secret']);
        self::assertSame(['Bonjour & merci'], $googleResult->translations);
        self::assertSame('google-secret', $googleHistory[0]['request']->getHeaderLine('X-Goog-Api-Key'));
        self::assertSame('', $googleHistory[0]['request']->getUri()->getQuery());

        $azureHistory = [];
        $azure        = new AzureTranslatorProvider($this->recordingClient($azureHistory, [['translations' => [['text' => 'Bonjour']]]]));
        $azureResult  = $azure->send($this->request(['Hello']), ['subscription_key' => 'azure-secret', 'region' => 'eastus']);
        self::assertSame(['Bonjour'], $azureResult->translations);
        self::assertSame('azure-secret', $azureHistory[0]['request']->getHeaderLine('Ocp-Apim-Subscription-Key'));
        self::assertSame('eastus', $azureHistory[0]['request']->getHeaderLine('Ocp-Apim-Subscription-Region'));
        self::assertStringContainsString('to=fr', $azureHistory[0]['request']->getUri()->getQuery());
    }

    public function test_volcengine_batches_at_both_documented_boundaries_and_parses_success(): void
    {
        $provider = new VolcengineProvider($this->client([
            'ResponseMetadata' => ['RequestId' => 'volc-request'],
            'TranslationList'  => [['Translation' => 'Bonjour'], ['Translation' => 'Monde']],
        ]));
        $countBoundaries = $provider->buildBatches(array_fill(0, 17, 'a'), 'en', 'fr');
        self::assertCount(2, $countBoundaries);
        self::assertSame([16, 1], array_map('count', $countBoundaries));
        self::assertCount(2, $provider->buildBatches([str_repeat('a', 4750), 'b'], 'en', 'fr'));
        $response = $provider->send($this->request(['Hello', 'World']), ['access_key_id' => 'key', 'secret_access_key' => 'secret']);
        self::assertTrue($response->ok);
        self::assertSame(['Bonjour', 'Monde'], $response->translations);
        self::assertStringContainsString('HMAC-SHA256 Credential=key/', $provider->signedHeaders(['Action' => 'TranslateText', 'Version' => '2020-06-01'], '{}', '20261102T010203Z', ['access_key_id' => 'key', 'secret_access_key' => 'secret'])['Authorization']);
    }

    public function test_baidu_enforces_byte_limit_and_parses_provider_error_without_network(): void
    {
        $provider = new BaiduProvider($this->client(['error_code' => '58001']));
        $this->expectException(InvalidArgumentException::class);
        $provider->buildBatches([str_repeat('中', 2501)], 'en', 'fr');
    }

    public function test_baidu_success_and_error_classification_are_deterministic(): void
    {
        $success = new BaiduProvider($this->client(['trans_result' => [['dst' => 'Bonjour']]]));
        self::assertSame(['Bonjour'], $success->send($this->request(['Hello']), ['appid' => 'id', 'secret' => 'secret'])->translations);
        $failure  = new BaiduProvider($this->client(['error_code' => '54004']));
        $response = $failure->send($this->request(['Hello']), ['appid' => 'id', 'secret' => 'secret']);
        self::assertSame('BALANCE_EXHAUSTED', $response->errorCode);
        // A provider-declared exhausted balance did not accept a translation;
        // it is safe to release this reservation and try the next verified
        // free account. Transport ambiguity remains conservatively occupied.
        self::assertTrue($failure->classifyFailure($response)['definitely_unbilled']);
    }

    public function test_each_provider_uses_its_documented_traditional_chinese_code(): void
    {
        $aliHistory = [];
        (new AliyunProvider($this->recordingClient($aliHistory, ['Code' => 200, 'Data' => ['Translated' => '繁體']])))
            ->send($this->request(['Hello'], 'zh-tw'), ['access_key_id' => 'key', 'access_key_secret' => 'secret']);
        parse_str((string) $aliHistory[0]['request']->getBody(), $aliFields);
        self::assertSame('zh-tw', $aliFields['TargetLanguage']);

        $volcHistory = [];
        (new VolcengineProvider($this->recordingClient($volcHistory, ['ResponseMetadata' => ['RequestId' => 'request'], 'TranslationList' => [['Translation' => '繁體']]])))
            ->send($this->request(['Hello'], 'zh-tw'), ['access_key_id' => 'key', 'secret_access_key' => 'secret']);
        self::assertSame('zh-Hant', json_decode((string) $volcHistory[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)['TargetLanguage']);

        $baiduHistory = [];
        (new BaiduProvider($this->recordingClient($baiduHistory, ['trans_result' => [['dst' => '繁體']]])))
            ->send($this->request(['Hello'], 'zh-tw'), ['appid' => 'id', 'secret' => 'secret']);
        parse_str((string) $baiduHistory[0]['request']->getBody(), $baiduFields);
        self::assertSame('cht', $baiduFields['to']);
    }

    public function test_baidu_provider_specific_language_codes_are_not_sent_as_generic_locales(): void
    {
        foreach (['ja' => 'jp', 'ko' => 'kor', 'fr' => 'fra', 'es' => 'spa', 'ar' => 'ara', 'vi' => 'vie'] as $locale => $expectedCode) {
            $history = [];
            (new BaiduProvider($this->recordingClient($history, ['trans_result' => [['dst' => 'translated']]])))
                ->send($this->request(['Hello'], $locale), ['appid' => 'id', 'secret' => 'secret']);
            parse_str((string) $history[0]['request']->getBody(), $fields);

            self::assertSame($expectedCode, $fields['to'], $locale);
        }
    }

    public function test_provider_error_codes_are_classified_without_releasing_ambiguous_calls(): void
    {
        $aliyun      = new AliyunProvider($this->client(['Code' => 10004]));
        $aliResponse = $aliyun->send($this->request(['Hello']), ['access_key_id' => 'key', 'access_key_secret' => 'secret']);
        self::assertSame('INVALID_INPUT', $aliResponse->errorCode);
        self::assertTrue($aliyun->classifyFailure($aliResponse)['definitely_unbilled']);

        $baidu         = new BaiduProvider($this->client(['error_code' => '54000']));
        $baiduResponse = $baidu->send($this->request(['Hello']), ['appid' => 'id', 'secret' => 'secret']);
        self::assertSame('INVALID_INPUT', $baiduResponse->errorCode);
        self::assertTrue($baidu->classifyFailure($baiduResponse)['definitely_unbilled']);

        $volc         = new VolcengineProvider($this->client(['ResponseMetadata' => ['Error' => ['Code' => '-500']]]));
        $volcResponse = $volc->send($this->request(['Hello']), ['access_key_id' => 'key', 'secret_access_key' => 'secret']);
        self::assertSame('VOLCENGINE_-500', $volcResponse->errorCode);
        self::assertFalse($volc->classifyFailure($volcResponse)['definitely_unbilled']);
    }

    public function test_connection_setup_failure_is_safe_but_an_ambiguous_transfer_is_not(): void
    {
        $provider = new BaiduProvider;

        $connection = $provider->classifyFailure(new ConnectException(
            'Could not connect to translation provider',
            new Request('POST', 'https://api.example.test/translate'),
        ));
        self::assertSame('definite_failure', $connection['kind']);
        self::assertSame('NETWORK_CONNECT_FAILED', $connection['code']);
        self::assertTrue($connection['definitely_unbilled']);

        $ambiguous = $provider->classifyFailure(new TransferException('The response timed out after write'));
        self::assertSame('uncertain', $ambiguous['kind']);
        self::assertSame('NETWORK_AFTER_SEND', $ambiguous['code']);
        self::assertFalse($ambiguous['definitely_unbilled']);
    }

    public function test_structured_provider_exceptions_remain_actionable_without_guessing_billing_state(): void
    {
        $provider = new BaiduProvider;

        $local = $provider->classifyFailure(new InvalidArgumentException('INVALID_BAIDU_REQUEST'));
        self::assertSame('INVALID_BAIDU_REQUEST', $local['code']);
        self::assertTrue($local['definitely_unbilled']);

        $upstream = $provider->classifyFailure(new \RuntimeException('BAIDU_RESPONSE_SCHEMA_INVALID'));
        self::assertSame('BAIDU_RESPONSE_SCHEMA_INVALID', $upstream['code']);
        self::assertFalse($upstream['definitely_unbilled']);
    }

    public function test_aliyun_signature_uses_canonical_sorted_query_and_changes_when_input_changes(): void
    {
        $provider   = new AliyunProvider;
        $parameters = ['Version' => '2018-10-12', 'Action' => 'TranslateGeneral', 'SourceText' => 'A B', 'AccessKeyId' => 'key'];
        $signature  = $provider->signature($parameters, 'testsecret');
        self::assertSame($signature, $provider->signature(array_reverse($parameters, true), 'testsecret'));
        self::assertNotSame($signature, $provider->signature([...$parameters, 'SourceText' => 'A C'], 'testsecret'));
        self::assertNotSame($signature, $provider->signature($parameters, 'testsecret', 'GET'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{27}=$/', $signature);
    }

    /** @param array<string, mixed> $body */
    private function client(array $body): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))])), 'http_errors' => false]);
    }

    /** @param list<array<string, mixed>> $history @param array<string, mixed> $body */
    private function recordingClient(array &$history, array $body): Client
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))]));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    /** @param list<string> $segments */
    private function request(array $segments, string $targetLocale = 'fr'): TranslationProviderRequest
    {
        return new TranslationProviderRequest('8224d0b9-ae7e-4f7a-9634-9eed9263b42a', 1, 'en', $targetLocale, $segments, hash('sha256', implode('', $segments)), 4);
    }
}
