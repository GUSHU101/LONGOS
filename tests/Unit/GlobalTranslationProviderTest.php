<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Providers\AliyunProvider;
use Plugin\LongosGlobalTranslate\Providers\BaiduProvider;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderRequest;
use Plugin\LongosGlobalTranslate\Providers\VolcengineProvider;

class GlobalTranslationProviderTest extends TestCase
{
    public static function supportCases(): iterable
    {
        foreach ([AliyunProvider::class, VolcengineProvider::class, BaiduProvider::class] as $provider) {
            yield $provider . ' supports en-fr' => [$provider, 'en', 'fr', true];
            yield $provider . ' supports en-us-de' => [$provider, 'en-US', 'de', true];
            yield $provider . ' rejects same language' => [$provider, 'en', 'en', false];
            yield $provider . ' rejects unknown language' => [$provider, 'en', 'xx', false];
        }
    }

    public static function storefrontTargetLocales(): iterable
    {
        foreach ([AliyunProvider::class, VolcengineProvider::class, BaiduProvider::class] as $provider) {
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
        self::assertFalse($failure->classifyFailure($response)['definitely_unbilled']);
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
