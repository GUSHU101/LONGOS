<?php

namespace Plugin\LongosLiveChat\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugin\LongosLiveChat\Models\Conversation;
use Plugin\LongosLiveChat\Models\Message;
use Throwable;

class ChatService
{
    public const MAX_PAGE_SIZE = 200;

    private const PRESENCE_TTL_SECONDS = 90;

    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/';

    private ?bool $supportsClientMessageIds = null;

    /**
     * @return array{conversation: Conversation, token: string, is_new: bool, messages: Collection<int, Message>, has_older: bool}
     */
    public function initialize(string $candidateToken, mixed $customer, string $welcomeMessage): array
    {
        $token = preg_match(self::TOKEN_PATTERN, $candidateToken) ? $candidateToken : Str::random(64);

        return DB::transaction(function () use ($token, $customer, $welcomeMessage): array {
            $effectiveToken = $token;
            $tokenHash      = hash('sha256', $effectiveToken);
            $conversation   = Conversation::query()->where('visitor_token', $tokenHash)->first();

            // A persistent guest token may survive a logout/login cycle on a
            // shared browser. Never attach one customer's transcript to a
            // different authenticated customer; rotate to a clean chat.
            if ($conversation && $conversation->customer_id
                              && (! $customer || (int) $conversation->customer_id !== (int) $customer->id)) {
                $effectiveToken = Str::random(64);
                $tokenHash      = hash('sha256', $effectiveToken);
                $conversation   = null;
            }

            if (! $conversation) {
                $conversation = Conversation::query()->firstOrCreate(['visitor_token' => $tokenHash], [
                    'public_id'     => (string) Str::uuid(),
                    'customer_id'   => $customer?->id,
                    'visitor_name'  => $this->customerValue($customer, 'name'),
                    'visitor_email' => $this->customerValue($customer, 'email'),
                    'status'        => Conversation::STATUS_OPEN,
                ]);
            }
            $isNew = $conversation->wasRecentlyCreated;

            if (! $isNew && $customer && (int) $conversation->customer_id !== (int) $customer->id) {
                $conversation->forceFill([
                    'customer_id'   => $customer->id,
                    'visitor_name'  => $conversation->visitor_name ?: $this->customerValue($customer, 'name'),
                    'visitor_email' => $conversation->visitor_email ?: $this->customerValue($customer, 'email'),
                ])->save();
            }

            $welcomeMessage = mb_substr($this->normalizeText($welcomeMessage), 0, 2000);
            if ($isNew && $welcomeMessage !== '') {
                $message = $conversation->messages()->create([
                    'sender_type' => Message::SENDER_SYSTEM,
                    'body'        => $welcomeMessage,
                ]);
                $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            }

            $page = $this->messagePage($conversation);
            $this->markDelivered($conversation, $page['messages'], Message::SENDER_VISITOR);

            return [
                'conversation' => $conversation->fresh(),
                'token'        => $effectiveToken,
                'is_new'       => $isNew,
                'messages'     => $page['messages'],
                'has_older'    => $page['has_older'],
            ];
        }, 3);
    }

    public function visitorConversation(string $publicId, string $token): Conversation
    {
        if (! Str::isUuid($publicId) || ! preg_match(self::TOKEN_PATTERN, $token)) {
            abort(404);
        }

        return Conversation::query()
            ->where('public_id', $publicId)
            ->where('visitor_token', hash('sha256', $token))
            ->firstOrFail();
    }

    /**
     * @return array{messages: Collection<int, Message>, has_older: bool, has_more: bool}
     */
    public function messagePage(
        Conversation $conversation,
        int $after = 0,
        int $before = 0,
        int $limit = self::MAX_PAGE_SIZE,
    ): array {
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $query = $conversation->messages();

        if ($after > 0) {
            $items   = $query->where('id', '>', $after)->oldest('id')->limit($limit + 1)->get();
            $hasMore = $items->count() > $limit;

            return [
                'messages'  => new Collection($items->take($limit)->values()->all()),
                'has_older' => false,
                'has_more'  => $hasMore,
            ];
        }

        if ($before > 0) {
            $query->where('id', '<', $before);
        }

        $items    = $query->latest('id')->limit($limit + 1)->get();
        $hasOlder = $items->count() > $limit;
        $messages = $items->take($limit)->sortBy('id')->values();

        return [
            'messages'  => new Collection($messages->all()),
            'has_older' => $hasOlder,
            'has_more'  => false,
        ];
    }

    /** @return array{message: Message, created: bool} */
    public function sendVisitor(
        Conversation $conversation,
        string $body,
        ?string $name,
        ?string $email,
        mixed $customer,
        ?string $clientMessageId,
    ): array {
        return DB::transaction(function () use ($conversation, $body, $name, $email, $customer, $clientMessageId): array {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $result = $this->createMessage(
                $locked,
                Message::SENDER_VISITOR,
                $customer?->id,
                $body,
                $clientMessageId,
            );

            $attributes = [
                'visitor_name'    => $this->optionalValue($name, $locked->visitor_name),
                'visitor_email'   => $this->optionalEmail($email, $locked->visitor_email),
                'customer_id'     => $customer?->id ?? $locked->customer_id,
            ];
            if ($result['created']) {
                $attributes['status']          = Conversation::STATUS_OPEN;
                $attributes['closed_at']       = null;
                $attributes['last_message_at'] = $result['message']->created_at;
            }
            $locked->forceFill($attributes)->save();

            return $result;
        }, 3);
    }

    /** @return array{message: Message, created: bool} */
    public function sendAdmin(
        Conversation $conversation,
        string $body,
        ?int $adminId,
        ?string $clientMessageId,
    ): array {
        return DB::transaction(function () use ($conversation, $body, $adminId, $clientMessageId): array {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $result = $this->createMessage(
                $locked,
                Message::SENDER_ADMIN,
                $adminId,
                $body,
                $clientMessageId,
            );
            if ($result['created']) {
                $locked->forceFill([
                    'status'          => Conversation::STATUS_OPEN,
                    'closed_at'       => null,
                    'last_message_at' => $result['message']->created_at,
                ])->save();
            }

            return $result;
        }, 3);
    }

    public function close(Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($conversation): Conversation {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== Conversation::STATUS_CLOSED || ! $locked->closed_at) {
                $locked->forceFill([
                    'status'    => Conversation::STATUS_CLOSED,
                    'closed_at' => $locked->closed_at ?: now(),
                ])->save();
            }

            return $locked->refresh();
        }, 3);
    }

    public function reopen(Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($conversation): Conversation {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== Conversation::STATUS_OPEN || $locked->closed_at) {
                $locked->forceFill([
                    'status'    => Conversation::STATUS_OPEN,
                    'closed_at' => null,
                ])->save();
            }

            return $locked->refresh();
        }, 3);
    }

    /** @param Collection<int, Message> $messages */
    public function markDelivered(Conversation $conversation, Collection $messages, string $recipient): void
    {
        $sender = $recipient === Message::SENDER_ADMIN ? Message::SENDER_VISITOR : Message::SENDER_ADMIN;
        $ids    = $messages->where('sender_type', $sender)->whereNull('read_at')->pluck('id');
        $now    = now();

        if ($ids->isNotEmpty()) {
            Message::query()
                ->where('conversation_id', $conversation->getKey())
                ->whereIn('id', $ids)
                ->whereNull('read_at')
                ->update(['read_at' => $now]);
        }

        $field = $recipient === Message::SENDER_ADMIN ? 'admin_last_read_at' : 'visitor_last_read_at';
        Conversation::query()->whereKey($conversation->getKey())->update([$field => $now]);
        $conversation->forceFill([$field => $now]);
    }

    public function markConversationRead(Conversation $conversation, string $recipient): void
    {
        $senders = $recipient === Message::SENDER_ADMIN
            ? [Message::SENDER_VISITOR]
            : [Message::SENDER_ADMIN, Message::SENDER_SYSTEM];
        $now = now();

        Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('sender_type', $senders)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        $field = $recipient === Message::SENDER_ADMIN ? 'admin_last_read_at' : 'visitor_last_read_at';
        Conversation::query()->whereKey($conversation->getKey())->update([$field => $now]);
        $conversation->forceFill([$field => $now]);
    }

    public function touchAdminPresence(?int $adminId): void
    {
        try {
            Cache::put($this->presenceKey(), [
                'admin_id' => $adminId,
                'seen_at'  => now()->timestamp,
            ], now()->addSeconds(self::PRESENCE_TTL_SECONDS));
        } catch (Throwable) {
            // Presence is an enhancement and must never take chat offline.
        }
    }

    public function adminOnline(): bool
    {
        try {
            return Cache::has($this->presenceKey());
        } catch (Throwable) {
            return false;
        }
    }

    public function cacheScope(): string
    {
        try {
            $connection = DB::connection();
            $identity   = implode('|', [
                $connection->getDriverName(),
                $connection->getDatabaseName(),
                (string) config('app.url'),
            ]);
        } catch (Throwable) {
            $identity = (string) config('app.url', 'longos');
        }

        return hash('sha256', $identity);
    }

    /** @return array{message: Message, created: bool} */
    private function createMessage(
        Conversation $conversation,
        string $senderType,
        ?int $senderId,
        string $body,
        ?string $clientMessageId,
    ): array {
        $body = $this->normalizeText($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw ValidationException::withMessages([
                'message' => trans('LongosLiveChat::chat.invalid_message'),
            ]);
        }

        $attributes = [
            'sender_type' => $senderType,
            'sender_id'   => $senderId,
            'body'        => $body,
        ];

        // Keep direct panel file uploads operational while a fail-closed SaaS
        // release is still applying this plugin's migration tenant by tenant.
        // Idempotency becomes active as soon as the new column is available.
        if ($clientMessageId && $this->supportsClientMessageIds()) {
            $attributes['client_message_id'] = $clientMessageId;
            $message                         = $conversation->messages()->firstOrCreate(
                ['client_message_id' => $clientMessageId],
                $attributes,
            );
        } else {
            $message = $conversation->messages()->create($attributes);
        }

        return ['message' => $message, 'created' => $message->wasRecentlyCreated];
    }

    private function supportsClientMessageIds(): bool
    {
        return $this->supportsClientMessageIds ??= Schema::hasColumn(
            'longos_live_chat_messages',
            'client_message_id',
        );
    }

    private function presenceKey(): string
    {
        return 'longos-live-chat:presence:' . $this->cacheScope();
    }

    private function normalizeText(?string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';

        return trim($value);
    }

    private function optionalValue(?string $value, ?string $fallback): ?string
    {
        $value = $this->normalizeText($value);

        return $value === '' ? $fallback : $value;
    }

    private function optionalEmail(?string $value, ?string $fallback): ?string
    {
        $value = mb_strtolower($this->normalizeText($value));

        return $value === '' ? $fallback : $value;
    }

    private function customerValue(mixed $customer, string $key): ?string
    {
        if (! $customer) {
            return null;
        }

        $value = $customer->{$key} ?? null;

        return is_scalar($value) ? $this->normalizeText((string) $value) ?: null : null;
    }
}
