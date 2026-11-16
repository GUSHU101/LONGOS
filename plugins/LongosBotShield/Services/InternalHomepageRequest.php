<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Request;

class InternalHomepageRequest
{
    public const HEADER = 'X-Longos-Bot-Shield-Internal';

    public function headerValue(string $host, ?int $timestamp = null, string $path = '/'): string
    {
        $secret = $this->secret();
        if ($secret === '') {
            return '';
        }

        $timestamp ??= time();
        $host      = strtolower(trim($host));
        $path      = '/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $signature = hash_hmac('sha256', implode('|', [$timestamp, 'GET', $host, $path]), $secret);

        return $timestamp . '.' . $signature;
    }

    public function valid(Request $request): bool
    {
        if (! $request->isMethod('GET') || $this->secret() === '') {
            return false;
        }

        $header = trim((string) $request->header(self::HEADER));
        if (preg_match('/^(\d{10})\.([a-f0-9]{64})$/D', $header, $match) !== 1) {
            return false;
        }

        $timestamp = (int) $match[1];
        if (abs(time() - $timestamp) > 30) {
            return false;
        }

        return hash_equals($this->headerValue($request->getHost(), $timestamp, $request->getPathInfo()), $header);
    }

    private function secret(): string
    {
        $key = trim((string) config('app.key', ''));

        return $key === '' ? '' : hash('sha256', $key . '|internal-homepage');
    }
}
