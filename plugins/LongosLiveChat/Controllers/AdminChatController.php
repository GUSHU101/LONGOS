<?php

namespace Plugin\LongosLiveChat\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugin\LongosLiveChat\Models\Conversation;
use Plugin\LongosLiveChat\Models\Message;
use Plugin\LongosLiveChat\Services\ChatService;

class AdminChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function index()
    {
        return view('LongosLiveChat::admin.index');
    }

    public function conversations(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', Conversation::STATUS_OPEN);
        $status = in_array($status, [Conversation::STATUS_OPEN, Conversation::STATUS_CLOSED, 'all'], true)
            ? $status
            : Conversation::STATUS_OPEN;
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $query  = Conversation::query()
            ->with('latestMessage')
            ->withCount(['messages as unread_count' => function ($query): void {
                $query->where('sender_type', Message::SENDER_VISITOR)->whereNull('read_at');
            }])
            ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END')
            ->latest('last_message_at')
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where(function ($query) use ($escaped): void {
                $query->where('visitor_name', 'like', "%{$escaped}%")
                    ->orWhere('visitor_email', 'like', "%{$escaped}%")
                    ->orWhere('public_id', 'like', "%{$escaped}%");
            });
        }

        $page      = min(10_000, max(1, $request->integer('page', 1)));
        $paginator = $query->simplePaginate(50, ['*'], 'page', $page);

        return $this->json([
            'conversations' => $paginator->getCollection()
                ->map(fn (Conversation $item): array => $this->conversationPayload($item))
                ->values(),
            'has_more'      => $paginator->hasMorePages(),
            'next_page'     => $paginator->hasMorePages() ? $page + 1 : null,
        ]);
    }

    public function messages(Request $request, string $publicId): JsonResponse
    {
        $conversation     = $this->conversation($publicId);
        [$after, $before] = $this->cursors($request);
        $page             = $this->chat->messagePage($conversation, $after, $before, 100);
        $this->chat->markDelivered($conversation, $page['messages'], Message::SENDER_ADMIN);

        return $this->json([
            'conversation' => $this->conversationPayload($conversation->fresh()),
            'messages'     => $this->messagesPayload($page['messages']),
            'has_older'    => $page['has_older'],
            'has_more'     => $page['has_more'],
        ]);
    }

    public function store(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'message'           => ['bail', 'required', 'string', 'max:5000', 'regex:/\S/'],
            'client_message_id' => ['nullable', 'uuid'],
        ]);
        $result = $this->chat->sendAdmin(
            $this->conversation($publicId),
            $data['message'],
            current_user()?->id,
            $data['client_message_id'] ?? null,
        );

        return $this->json(
            ['message' => $this->messagePayload($result['message'])],
            $result['created'] ? 201 : 200,
        );
    }

    public function close(string $publicId): JsonResponse
    {
        $conversation = $this->chat->close($this->conversation($publicId));

        return $this->json(['conversation' => $this->conversationPayload($conversation)]);
    }

    public function reopen(string $publicId): JsonResponse
    {
        $conversation = $this->chat->reopen($this->conversation($publicId));

        return $this->json(['conversation' => $this->conversationPayload($conversation)]);
    }

    public function heartbeat(): JsonResponse
    {
        $this->chat->touchAdminPresence(current_user()?->id);

        return $this->json(['online' => true]);
    }

    private function conversation(string $publicId): Conversation
    {
        abort_unless(Str::isUuid($publicId), 404);

        return Conversation::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** @return array{0: int, 1: int} */
    private function cursors(Request $request): array
    {
        $after  = max(0, $request->integer('after'));
        $before = max(0, $request->integer('before'));

        return $after > 0 ? [$after, 0] : [0, $before];
    }

    private function conversationPayload(Conversation $conversation): array
    {
        return [
            'id'              => $conversation->public_id,
            'name'            => $conversation->visitor_name ?: ($conversation->visitor_email ?: trans('LongosLiveChat::chat.visitor')),
            'email'           => $conversation->visitor_email,
            'status'          => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread_count'    => (int) ($conversation->unread_count ?? 0),
            'preview'         => Str::limit((string) $conversation->latestMessage?->body, 90),
        ];
    }

    private function messagesPayload(iterable $messages): array
    {
        return collect($messages)->map(fn (Message $message): array => $this->messagePayload($message))->values()->all();
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

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'same-origin',
        ]);
    }
}
