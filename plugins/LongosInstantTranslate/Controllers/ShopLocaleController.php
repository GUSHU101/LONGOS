<?php

namespace Plugin\LongosInstantTranslate\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Plugin\LongosInstantTranslate\Services\LocaleResolver;
use Plugin\LongosInstantTranslate\Services\VerifiedVisitorCountry;

final class ShopLocaleController
{
    public function __invoke(
        Request $request,
        InstantTranslateSettings $settings,
        VerifiedVisitorCountry $countryService,
        LocaleResolver $resolver,
    ): JsonResponse {
        abort_if(current_site() === null || ! $settings->runtimeEnabled(), 404);

        $configuration = $settings->all();
        $country       = $configuration['auto_country'] ? $countryService->countryCode($request) : null;
        $decision      = $resolver->decide(
            $country,
            $request->header('Accept-Language'),
            $configuration['enabled_languages'],
            (bool) $configuration['auto_country'],
            (bool) $configuration['browser_fallback'],
        );

        return response()->json([
            'country'          => $country,
            'country_verified' => $country !== null,
            'language'         => $decision['language'],
            'source'           => $decision['source'],
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Pragma'        => 'no-cache',
            'Vary'          => 'Accept-Language, Cookie',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }
}
