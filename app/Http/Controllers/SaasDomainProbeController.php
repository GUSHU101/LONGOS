<?php

namespace App\Http\Controllers;

use App\Saas\DomainProbeSignature;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SaasDomainProbeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $host      = DomainName::normalize($request->getHost());
            $domainId  = filter_var($request->query('id'), FILTER_VALIDATE_INT);
            $signature = (string) $request->query('signature', '');
            $domain    = $domainId ? Domain::query()
                ->whereKey($domainId)
                ->where('host', $host)
                ->whereIn('status', [Domain::STATUS_ACTIVATING, Domain::STATUS_ACTIVE])
                ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
                ->first() : null;

            $authorized = $domain !== null
                && strlen($signature) === 64
                && hash_equals(DomainProbeSignature::make($domain), $signature);
        } catch (Throwable) {
            $authorized = false;
        }

        return $authorized
            ? response('', 204, ['Cache-Control' => 'no-store', 'X-Longos-Domain-Probe' => 'ready'])
            : response('', 404, ['Cache-Control' => 'no-store']);
    }
}
