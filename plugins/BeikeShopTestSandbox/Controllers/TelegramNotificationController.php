<?php

namespace Plugin\BeikeShopTestSandbox\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Plugin\BeikeShopTestSandbox\Services\TelegramOrderNotificationService;

class TelegramNotificationController extends Controller
{
    public function __construct(private TelegramOrderNotificationService $telegram) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'telegram' => $this->telegram->status(),
        ]);
    }

    public function test(): JsonResponse
    {
        $result = $this->telegram->sendTestNotification();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function discoverChats(): JsonResponse
    {
        $result = $this->telegram->discoverChats();

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
