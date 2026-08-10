<?php

namespace App\Models;

use App\Enums\WhatsAppConversationState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The state machine for one WhatsApp number's conversation with the bot.
 *
 * @property int $id
 * @property string $whatsapp_phone
 * @property int|null $user_id
 * @property WhatsAppConversationState $state
 * @property array<string, mixed>|null $context
 * @property CarbonInterface|null $last_interaction_at
 */
#[Fillable([
    'whatsapp_phone',
    'user_id',
    'state',
    'context',
    'last_interaction_at',
])]
class WhatsappConversation extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return to the top of the flow and forget any partial selection.
     */
    public function reset(): void
    {
        $this->update([
            'state' => WhatsAppConversationState::Idle,
            'context' => null,
        ]);
    }

    /**
     * Move to a new step, replacing the scratch context.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function moveTo(WhatsAppConversationState $state, ?array $context = null): void
    {
        $this->update([
            'state' => $state,
            'context' => $context,
            'last_interaction_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => WhatsAppConversationState::class,
            'context' => 'array',
            'last_interaction_at' => 'datetime',
        ];
    }
}
