<?php

namespace App\Saas;

use App\Saas\Models\Domain;

class DomainProbeSignature
{
    public static function make(Domain $domain): string
    {
        $key = (string) config('app.key');

        return hash_hmac('sha256', implode('|', [
            (string) $domain->getKey(),
            strtolower((string) $domain->host),
            (string) $domain->site_id,
        ]), $key);
    }
}
