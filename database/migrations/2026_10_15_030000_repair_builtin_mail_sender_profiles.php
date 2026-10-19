<?php

use App\Mail\MailSenderProfileService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! (bool) config('mail.relay.enabled') || ! Schema::hasTable('settings')) {
            return;
        }

        $engine = strtolower(trim((string) $this->settingValue('mail_engine', '')));
        if ($engine !== '' && $engine !== 'self_hosted') {
            // Never replace an explicitly configured SMTP/API provider.
            return;
        }

        $legacy  = $this->jsonSetting('mail_from');
        $stored  = $this->jsonSetting('mail_senders');
        $service = app(MailSenderProfileService::class);
        $profiles = $service->repairBundledRelayProfiles($stored, $legacy);

        $this->store('mail_engine', 'self_hosted', false);
        $this->store('mail_senders', $profiles, true);
        $this->store('mail_from', [
            'address' => $profiles[MailSenderProfileService::DEFAULT]['address'],
            'name'    => $profiles[MailSenderProfileService::DEFAULT]['name'],
        ], true);
    }

    /** @return array<string, mixed> */
    private function jsonSetting(string $name): array
    {
        $value = $this->settingValue($name, '');
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function settingValue(string $name, mixed $default): mixed
    {
        return DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', $name)
            ->value('value') ?? $default;
    }

    private function store(string $name, mixed $value, bool $json): void
    {
        $key = [
            'type'  => 'system',
            'space' => 'base',
            'name'  => $name,
        ];
        $payload = [
            'value' => $json
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : (string) $value,
            'json'       => $json ? 1 : 0,
            'updated_at' => now(),
        ];
        $query = DB::table('settings')->where($key);
        if ($query->exists()) {
            $query->update($payload);

            return;
        }

        DB::table('settings')->insert(array_merge($key, $payload, ['created_at' => now()]));
    }

    public function down(): void
    {
        // A rollback must not restore invalid or cross-tenant sender identities.
    }
};
