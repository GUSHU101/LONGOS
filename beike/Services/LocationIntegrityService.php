<?php

namespace Beike\Services;

use Beike\Models\Country;
use Beike\Models\Zone;

class LocationIntegrityService
{
    /** @var array<int, string> */
    private const BULGARIA_ZONE_CODES = [
        479 => 'BG-01', 480 => 'BG-02', 481 => 'BG-08', 482 => 'BG-07',
        483 => 'BG-26', 484 => 'BG-09', 485 => 'BG-10', 486 => 'BG-11',
        487 => 'BG-12', 488 => 'BG-13', 489 => 'BG-14', 490 => 'BG-15',
        491 => 'BG-16', 492 => 'BG-17', 493 => 'BG-27', 494 => 'BG-19',
        495 => 'BG-20', 496 => 'BG-21', 497 => 'BG-23', 498 => 'BG-22',
        499 => 'BG-24', 500 => 'BG-25', 501 => 'BG-03', 502 => 'BG-04',
        503 => 'BG-05', 504 => 'BG-06', 505 => 'BG-28', 3968 => 'BG-18',
    ];

    /**
     * Inspect the live catalog without mutating it.
     *
     * @return array{checked: array<string, int>, issues: array<int, array<string, int|string>>}
     */
    public function audit(): array
    {
        $report = [
            'checked' => ['countries' => 0, 'zones' => 0],
            'issues'  => [],
        ];

        $countryCodes = [];
        foreach (Country::query()->select('id', 'name', 'code', 'continent')->get() as $country) {
            $report['checked']['countries']++;
            $code = $this->normalizeCode($country->code);
            if ($code === '') {
                $this->issue($report, 'country_missing_code', $country->id, 'Country code is empty.');
            } else {
                $countryCodes[$code][] = $country->id;
            }
            if ($country->name === '') {
                $this->issue($report, 'country_missing_name', $country->id, 'Country name is empty.');
            }
            if (! in_array($country->continent, Country::CONTINENTS, true)) {
                $this->issue($report, 'country_invalid_continent', $country->id, 'Country continent is not supported.');
            }
        }

        foreach ($countryCodes as $code => $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $this->issue($report, 'duplicate_country_code', $id, "Duplicate country code {$code}.");
                }
            }
        }

        $zoneCodes = [];
        foreach (Zone::query()->with('country:id,code')->select('id', 'country_id', 'name', 'code', 'active')->get() as $zone) {
            $report['checked']['zones']++;
            if (! $zone->country) {
                $this->issue($report, 'zone_missing_country', $zone->id, 'Zone references a missing country.');

                continue;
            }

            if ($zone->name === '') {
                $this->issue($report, 'zone_missing_name', $zone->id, 'Zone name is empty.');
            }
            if ($this->decodeName($zone->name) !== $zone->name) {
                $this->issue($report, 'zone_html_entity_name', $zone->id, 'Zone name contains an HTML entity.');
            }

            $code = $this->normalizeCode($zone->code);
            if ($code === '') {
                $this->issue($report, 'zone_missing_code', $zone->id, 'Zone code is empty.');
            } else {
                $zoneCodes[$zone->country_id . '|' . $code][] = $zone->id;
            }

            if ($this->isInvalidLegacyZone($zone) && $zone->active) {
                $this->issue($report, 'zone_invalid_legacy_entry', $zone->id, 'Los Angeles cannot be a province of American Samoa.');
            }
        }

        foreach ($zoneCodes as $code => $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $this->issue($report, 'duplicate_zone_code', $id, "Duplicate zone code {$code}.");
                }
            }
        }

        return $report;
    }

    /**
     * Apply only deterministic, non-destructive catalog repairs.
     *
     * @return array<string, int>
     */
    public function repair(): array
    {
        $repaired = [
            'continents'         => 0,
            'encoded_zone_names' => 0,
            'bulgaria_codes'     => 0,
            'disabled_legacy'    => 0,
        ];

        $repaired['continents'] = Country::query()
            ->whereIn('continent', ['none', ''])
            ->update(['continent' => 'null']);

        Zone::query()->where('name', 'like', '%&%')->select('id', 'name')->orderBy('id')->chunkById(200, function ($zones) use (&$repaired) {
            foreach ($zones as $zone) {
                $name = $this->decodeName($zone->name);
                if ($name !== $zone->name) {
                    Zone::query()->whereKey($zone->id)->update(['name' => $name]);
                    $repaired['encoded_zone_names']++;
                }
            }
        });

        foreach (self::BULGARIA_ZONE_CODES as $id => $code) {
            $updated = Zone::query()
                ->whereKey($id)
                ->where('country_id', 33)
                ->where('code', '')
                ->update(['code' => $code]);
            $repaired['bulgaria_codes'] += $updated;
        }

        $repaired['disabled_legacy'] = Zone::query()
            ->whereKey(4241)
            ->where('country_id', 4)
            ->where('name', 'Los Angeles')
            ->where('code', 'LosAngeles')
            ->where('active', true)
            ->update(['active' => false]);

        return $repaired;
    }

    private function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    private function decodeName(string $name): string
    {
        return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function isInvalidLegacyZone(Zone $zone): bool
    {
        return (int) $zone->country_id === 4
            && $zone->name             === 'Los Angeles'
            && $zone->code             === 'LosAngeles';
    }

    /** @param array{checked: array<string, int>, issues: array<int, array<string, int|string>>} $report */
    private function issue(array &$report, string $type, int $id, string $message): void
    {
        $report['issues'][] = compact('type', 'id', 'message');
    }
}
