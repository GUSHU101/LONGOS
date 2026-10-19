<?php

namespace Plugin\LongosLiveChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'longos_live_chat_conversations';

    protected $fillable = [
        'public_id',
        'visitor_token',
        'customer_id',
        'visitor_name',
        'visitor_email',
        'status',
        'closed_at',
        'last_message_at',
        'admin_last_read_at',
        'visitor_last_read_at',
    ];

    protected $casts = [
        'closed_at'            => 'datetime',
        'last_message_at'      => 'datetime',
        'admin_last_read_at'   => 'datetime',
        'visitor_last_read_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
    }
}
