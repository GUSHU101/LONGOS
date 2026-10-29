<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MailSettingsPresentationTest extends TestCase
{
    public function test_brevo_is_the_primary_flow_and_legacy_relay_is_explicitly_opt_in(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/resources/beike/admin/views/pages/setting/mail.blade.php');

        self::assertStringContainsString("\$showLegacyRelay = request()->boolean('legacy_mail_relay')", $view);
        self::assertStringContainsString("\$savedMailEngine === 'self_hosted' && \$showLegacyRelay", $view);
        self::assertStringNotContainsString("\$savedMailEngine === 'self_hosted')\n    <div class=\"card mb-4\" id=\"mail-relay-topology\"", $view);
        self::assertStringContainsString('mail-settings-provider-choice', $view);
        self::assertStringContainsString('mail-workspace-hero', $view);
        self::assertStringContainsString('mail-setup-nav', $view);
        self::assertStringContainsString('mail-notification-matrix', $view);
        self::assertStringContainsString('mail-action-grid', $view);
        self::assertStringContainsString('mail-save-dock', $view);
        self::assertStringContainsString('id="mail-engine-brevo"', $view);
        self::assertStringContainsString('name="mail_engine" value="{{ $savedMailEngine }}" :value="mail_engine"', $view);
        self::assertStringContainsString('@click="switchToBrevo"', $view);
        self::assertStringContainsString("request()->fullUrlWithQuery(['legacy_mail_relay' => 1])", $view);
    }

    public function test_unsaved_engine_changes_cannot_run_the_saved_engine_by_accident(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/resources/beike/admin/views/pages/setting/mail.blade.php');

        self::assertStringContainsString('savedMailEngine: mailPageData.mailEngine', $view);
        self::assertStringContainsString('hasUnsavedEngineChange()', $view);
        self::assertStringContainsString('this.mail_engine !== this.savedMailEngine', $view);
        self::assertStringContainsString('this.diagnosing || this.hasUnsavedEngineChange || !this.mail_engine', $view);
        self::assertStringContainsString('this.testing || this.hasUnsavedEngineChange || !this.mail_engine', $view);
        self::assertStringContainsString("this.savedMailEngine !== 'brevo'", $view);
    }

    public function test_new_mail_settings_copy_is_available_in_primary_locales(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['en', 'zh_cn'] as $locale) {
            $copy = require $root . "/lang/{$locale}/admin/setting.php";

            foreach ([
                'mail_migration_title',
                'mail_migration_action',
                'mail_workspace_title',
                'mail_setup_nav_label',
                'mail_notification_event',
                'mail_test_delivery_help',
                'mail_save_dock_title',
                'mail_save_before_verification',
                'mail_legacy_relay_open',
                'mail_legacy_relay_console_title',
            ] as $key) {
                self::assertArrayHasKey($key, $copy, "Missing {$locale} copy for {$key}");
                self::assertNotSame('', trim((string) $copy[$key]));
            }
        }
    }
}
