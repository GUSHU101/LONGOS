<?php

namespace Plugin\LongosLiveChat\Controllers;

use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Plugin\LongosLiveChat\Models\Message;
use Plugin\LongosLiveChat\Services\ChatService;

class ShopChatController extends Controller
{
    private const COOKIE = 'longos_live_chat_visitor';

    public function __construct(private readonly ChatService $chat) {}

    public function init(Request $request): JsonResponse
    {
        $rateLimitKey = 'longos-live-chat:init:' . $this->chat->cacheScope() . ':' . hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            return $this->rateLimited($rateLimitKey);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $result = $this->chat->initialize(
            (string) $request->cookie(self::COOKIE, ''),
            current_customer(),
            (string) plugin_setting(
                'longos_live_chat.welcome_message',
                'Hello! Leave us a message and we will get back to you shortly.',
            ),
        );

        $response = $this->json([
            'conversation'    => $this->conversationPayload($result['conversation']),
            'messages'        => $this->messagesPayload($result['messages']),
            'has_older'       => $result['has_older'],
            'operator_online' => $this->chat->adminOnline(),
        ]);

        return $response->cookie(
            self::COOKIE,
            $result['token'],
            60 * 24 * 90,
            '/',
            null,
            $request->isSecure() || is_secure(),
            true,
            false,
            'Lax',
        );
    }

    public function messages(Request $request, string $publicId): JsonResponse
    {
        $conversation = $this->chat->visitorConversation(
            $publicId,
            (string) $request->cookie(self::COOKIE, ''),
        );
        [$after, $before] = $this->cursors($request);
        $page             = $this->chat->messagePage($conversation, $after, $before, 100);
        if ($request->boolean('mark_read', true)) {
            $this->chat->markDelivered($conversation, $page['messages'], Message::SENDER_VISITOR);
        }

        return $this->json([
            'messages'        => $this->messagesPayload($page['messages']),
            'has_older'       => $page['has_older'],
            'has_more'        => $page['has_more'],
            'operator_online' => $this->chat->adminOnline(),
        ]);
    }

    public function store(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'message'           => ['bail', 'required', 'string', 'max:5000', 'regex:/\S/'],
            'name'              => ['nullable', 'string', 'max:120'],
            'email'             => ['nullable', 'email:rfc', 'max:255'],
            'client_message_id' => ['nullable', 'uuid'],
        ]);
        $conversation = $this->chat->visitorConversation(
            $publicId,
            (string) $request->cookie(self::COOKIE, ''),
        );

        $rateLimitKey = 'longos-live-chat:send:' . $this->chat->cacheScope() . ':' . $conversation->id . ':' . hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 12)) {
            return $this->rateLimited($rateLimitKey);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $result = $this->chat->sendVisitor(
            $conversation,
            $data['message'],
            $data['name']  ?? null,
            $data['email'] ?? null,
            current_customer(),
            $data['client_message_id'] ?? null,
        );

        return $this->json(
            ['message' => $this->messagePayload($result['message'])],
            $result['created'] ? 201 : 200,
        );
    }

    public function read(Request $request, string $publicId): JsonResponse
    {
        $conversation = $this->chat->visitorConversation(
            $publicId,
            (string) $request->cookie(self::COOKIE, ''),
        );
        $this->chat->markConversationRead($conversation, Message::SENDER_VISITOR);

        return $this->json(['read' => true]);
    }

    /** @return array{0: int, 1: int} */
    private function cursors(Request $request): array
    {
        $after  = max(0, $request->integer('after'));
        $before = max(0, $request->integer('before'));

        return $after > 0 ? [$after, 0] : [0, $before];
    }

    private function rateLimited(string $key): JsonResponse
    {
        $retryAfter = max(1, RateLimiter::availableIn($key));

        return $this->json([
            'message'     => trans('LongosLiveChat::chat.rate_limited'),
            'retry_after' => $retryAfter,
        ], 429)->header('Retry-After', (string) $retryAfter);
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
            'Vary'                   => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'same-origin',
        ]);
    }

    private function messagePayload(Message $message): array
    {
        return [
            'id'          => $message->id,
            'sender_type' => $message->sender_type,
            'body'        => $message->body,
            'created_at'  => $message->created_at?->toIso8601String(),
        ];
    }

    private function messagesPayload(iterable $messages): array
    {
        return collect($messages)->map(fn (Message $message): array => $this->messagePayload($message))->values()->all();
    }

    private function conversationPayload($conversation): array
    {
        return ['id' => $conversation->public_id, 'status' => $conversation->status];
    }
}
