<?php

namespace Beike\Services;

use Beike\Repositories\SettingRepo;

class LocationCatalogExclusionService
{
    public function countryCodes(): array
    {
        return $this->values('excluded_countries');
    }

    public function zoneKeys(): array
    {
        return $this->values('excluded_zones');
    }

    public function excludeCountry(string $code): void
    {
        $this->store('excluded_countries', [...$this->countryCodes(), strtoupper(trim($code))]);
    }

    public function restoreCountry(string $code): void
    {
        $code = strtoupper(trim($code));
        $this->store('excluded_countries', array_values(array_filter(
            $this->countryCodes(),
            static fn (string $item): bool => $item !== $code,
        )));
    }

    public function excludeZone(string $countryCode, string $zoneCode): void
    {
        $this->store('excluded_zones', [...$this->zoneKeys(), $this->zoneKey($countryCode, $zoneCode)]);
    }

    public function restoreZone(string $countryCode, string $zoneCode): void
    {
        $key = $this->zoneKey($countryCode, $zoneCode);
        $this->store('excluded_zones', array_values(array_filter(
            $this->zoneKeys(),
            static fn (string $item): bool => $item !== $key,
        )));
    }

    public function zoneKey(string $countryCode, string $zoneCode): string
    {
        return strtoupper(trim($countryCode)) . '|' . strtoupper(trim($zoneCode));
    }

    private function values(string $name): array
    {
        $values = system_setting("location_catalog.{$name}", []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            is_array($values) ? $values : [],
        ))));
    }

    private function store(string $name, array $values): void
    {
        SettingRepo::storeValue($name, array_values(array_unique(array_filter($values))), 'location_catalog');
    }
}
