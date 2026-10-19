<?php

namespace App\Saas\Dns;

use App\Saas\Contracts\DnsRouteResolver;

class NativeDnsRouteResolver implements DnsRouteResolver
{
    public function resolve(string $host): array
    {
        return $this->resolveHost(strtolower(rtrim(trim($host), '.')), 0, []);
    }

    /** @param array<int, string> $visited */
    private function resolveHost(string $host, int $depth, array $visited): array
    {
        if ($host === '' || $depth > 5 || in_array($host, $visited, true)) {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $visited[] = $host;
        $records   = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        $values    = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $values[] = strtolower($address);
            }

            $target = $record['target'] ?? null;
            if (is_string($target) && $target !== '') {
                $target   = strtolower(rtrim($target, '.'));
                $values[] = $target;
                $values   = [...$values, ...$this->resolveHost($target, $depth + 1, $visited)];
            }
        }

        return array_values(array_unique($values));
    }
}
