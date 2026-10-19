<?php

namespace App\Saas\Dns;

use App\Saas\Contracts\DnsTxtResolver;

class NativeDnsTxtResolver implements DnsTxtResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        $answers = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $value = $record['txt'] ?? null;
            if (! is_string($value) && is_array($record['entries'] ?? null)) {
                $value = implode('', array_filter($record['entries'], 'is_string'));
            }
            if (is_string($value) && $value !== '') {
                $answers[] = trim($value);
            }
        }

        return array_values(array_unique($answers));
    }
}
