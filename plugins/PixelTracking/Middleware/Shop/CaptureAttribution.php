<?php

namespace Plugin\PixelTracking\Middleware\Shop;

use Closure;
use Illuminate\Http\Request;

class CaptureAttribution
{
    public function handle(Request $request, Closure $next): mixed
    {
        $hasConsent = true;
        if (plugin_setting('pixel_tracking.require_consent', false)) {
            $cookie = (string) plugin_setting('pixel_tracking.consent_cookie', 'tracking_consent');
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $cookie) !== 1) {
                $cookie = 'tracking_consent';
            }
            $value      = strtolower((string) $request->cookie($cookie, ''));
            $hasConsent = in_array($value, ['1', 'true', 'yes'], true);
            session(['pixel_tracking.consent' => $hasConsent]);
        }

        // When consent mode is enabled, click identifiers and campaign
        // parameters are not retained before the visitor opts in.
        if (! $hasConsent) {
            session()->forget([
                'pixel_tracking.attribution',
                'pixel_tracking.browser_identifiers',
            ]);

            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'ttclid'];
            $data = [];
            foreach ($request->only($keys) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $value = mb_substr(trim($value), 0, 500);
                if ($value !== '') {
                    $data[$key] = $value;
                }
            }
            if ($data) {
                if (isset($data['fbclid']) && $data['fbclid'] !== session('pixel_tracking.attribution.fbclid')) {
                    $data['fbclid_time'] = time();
                }
                session(['pixel_tracking.attribution' => array_merge(session('pixel_tracking.attribution', []), $data)]);
            }

            $identifiers = array_filter([
                'fbp' => (string) $request->cookie('_fbp', ''),
                'fbc' => (string) $request->cookie('_fbc', ''),
                'ttp' => (string) $request->cookie('_ttp', ''),
            ]);
            if ($identifiers) {
                session(['pixel_tracking.browser_identifiers' => array_merge(session('pixel_tracking.browser_identifiers', []), $identifiers)]);
            }
        }

        return $next($request);
    }
}
