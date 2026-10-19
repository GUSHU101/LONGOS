<?php

namespace App\Saas\Events;

use App\Saas\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Domain $domain) {}
}
