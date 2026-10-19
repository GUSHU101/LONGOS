<?php

namespace App\Saas\Contracts;

use App\Saas\Models\Domain;

interface DomainTrafficProbe
{
    /**
     * Prove that the public HTTPS endpoint terminates a valid certificate and
     * routes the requested Host back to this LONGOS installation.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function probe(Domain $domain): array;
}
