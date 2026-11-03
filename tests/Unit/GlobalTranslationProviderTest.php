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

    #[DataProvider('supportCases')]
    public function test_provider_language_capabilities_are_explicit(string $class, string $source, string $target, bool $expected): void
    {
        self::assertSame($expected, (new $class)->supports($source, $target));
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

    public function test_aliyun_uses_a_signed_post_form_so_credentials_and_text_never_enter_a_url(): void
    {
        $history = [];
        $client = new Client([
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
        self::assertCount(2, $provider->buildBatches(array_fill(0, 17, 'a'), 'en', 'fr'));
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

    /** @param list<string> $segments */
    private function request(array $segments): TranslationProviderRequest
    {
        return new TranslationProviderRequest('8224d0b9-ae7e-4f7a-9634-9eed9263b42a', 1, 'en', 'fr', $segments, hash('sha256', implode('', $segments)), 4);
    }
}
