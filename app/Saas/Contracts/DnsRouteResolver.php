<?php

namespace App\Saas\Contracts;

interface DnsRouteResolver
{
    /**
     * Resolve the public routing values for a host. The result may contain
     * A/AAAA addresses and CNAME targets (without a trailing dot).
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array;
}
