<?php

namespace App\Saas\Contracts;

interface DnsTxtResolver
{
    /** @return array<int, string> */
    public function resolve(string $host): array;
}
