<?php

namespace Plugin\LongosLiveChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const SENDER_VISITOR = 'visitor';

    public const SENDER_ADMIN = 'admin';

    public const SENDER_SYSTEM = 'system';

    protected $table = 'longos_live_chat_messages';

    protected $fillable = ['conversation_id', 'sender_type', 'sender_id', 'body', 'client_message_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
