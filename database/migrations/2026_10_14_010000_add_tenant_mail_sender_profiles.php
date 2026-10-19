<?php

use App\Mail\MailSenderProfileService;
use App\Saas\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $exists = DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', 'mail_senders')
            ->exists();
        if ($exists) {
            return;
        }

        $service  = app(MailSenderProfileService::class);
        $legacy   = $this->legacyProfile();
        $profiles = [];
        foreach (array_keys(MailSenderProfileService::categories()) as $category) {
            $suggested           = $service->suggestedAddress($category);
            $profiles[$category] = [
                'address'  => $category === MailSenderProfileService::DEFAULT
                    ? $this->safeDefaultAddress($legacy['address'], $suggested)
                    : $suggested,
                'name'     => $legacy['name'],
                'reply_to' => '',
            ];
        }

        DB::table('settings')->insert([
            'type'       => 'system',
            'space'      => 'base',
            'name'       => 'mail_senders',
            'value'      => json_encode($profiles, JSON_UNESCAPED_UNICODE),
            'json'       => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Upgrade a legacy generic platform sender to the tenant-scoped
        // default while keeping custom-domain and control-site choices intact.
        if ($profiles[MailSenderProfileService::DEFAULT]['address'] !== $legacy['address']) {
            DB::table('settings')
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', 'mail_from')
                ->update([
                    'value' => json_encode([
                        'address' => $profiles[MailSenderProfileService::DEFAULT]['address'],
                        'name'    => $legacy['name'],
                    ], JSON_UNESCAPED_UNICODE),
                    'json'       => 1,
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array{address: string, name: string} */
    private function legacyProfile(): array
    {
        $row = DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', 'mail_from')
            ->first();
        $value = $row ? json_decode((string) $row->value, true) : [];

        return [
            'address' => trim((string) ($value['address'] ?? config('mail.from.address', ''))),
            'name'    => trim((string) ($value['name'] ?? config('mail.from.name', config('app.name', 'LONGOS')))),
        ];
    }

    private function safeDefaultAddress(string $legacy, string $suggested): string
    {
        $platformDomain = strtolower(trim((string) config('mail.relay.platform_sender_domain')));
        $legacyDomain   = strtolower((string) substr(strrchr($legacy, '@') ?: '', 1));
        $site           = Site::current();

        if ((bool) config('saas.enabled') && $site && ! (bool) data_get($site->meta, 'control_site', false)
                                          && $platformDomain !== '' && $legacyDomain === $platformDomain) {
            return $suggested;
        }

        return $legacy !== '' ? $legacy : $suggested;
    }

    public function down(): void
    {
        // Preserve sender identity choices on rollback.
    }
};
