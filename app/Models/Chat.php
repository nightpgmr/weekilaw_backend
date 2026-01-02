<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chat extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'messages',
        'last_message_at',
    ];

    protected $casts = [
        'messages' => 'array',
        'last_message_at' => 'datetime',
    ];

    /**
     * Get the user that owns the chat.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a title from the first message if not set
     */
    public function generateTitle(): string
    {
        if ($this->title) {
            return $this->title;
        }

        if ($this->messages && count($this->messages) > 0) {
            $firstMessage = $this->messages[0];
            if (isset($firstMessage['question'])) {
                $question = $firstMessage['question'];
                // Take first 50 characters as title
                return mb_substr($question, 0, 50) . (mb_strlen($question) > 50 ? '...' : '');
            }
        }

        return 'چت جدید';
    }
}
