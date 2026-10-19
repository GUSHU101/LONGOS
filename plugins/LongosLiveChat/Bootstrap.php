<?php

namespace Plugin\LongosLiveChat;

use Beike\Services\DesignService;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('admin.design.index.data', function (array $data): array {
            $data['editor_app_embeds'][] = [
                'code'            => 'longos_live_chat',
                'name'            => trans('LongosLiveChat::chat.menu'),
                'description'     => '在店铺前台显示 LONGOS 在线客服入口。',
                'default_enabled' => true,
            ];

            return $data;
        });

        add_hook_blade('layout.master.footer.code', function ($callback, $output, $data) {
            if (! DesignService::isAppEmbedEnabled('longos_live_chat')) {
                return $output;
            }

            // Some themes expose the footer hook through nested layouts. Do
            // not mount two widgets or duplicate polling timers on one page.
            if (str_contains((string) $output, 'id="longos-live-chat"')) {
                return $output;
            }

            return $output . view('LongosLiveChat::shop.widget')->render();
        });

        add_hook_filter('admin.components.sidebar.menus', function (array $menus) {
            $menus[] = [
                'route'    => 'longos_live_chat.index',
                'title'    => trans('LongosLiveChat::chat.menu'),
                'icon'     => 'bi bi-chat-dots',
                'prefixes' => ['longos_live_chat'],
                'children' => [],
            ];

            return $menus;
        });
    }
}
