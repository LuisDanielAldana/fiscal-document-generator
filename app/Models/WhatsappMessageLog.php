<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for every WhatsApp message in and out.
 *
 * `whatsapp_message_id` is unique, which is also what makes inbound webhook
 * handling idempotent: Meta retries deliveries, and a replay must not be
 * processed twice.
 *
 * @property int $id
 * @property string|null $whatsapp_message_id
 * @property string $direction
 * @property string $whatsapp_phone
 * @property array<string, mixed> $payload
 * @property string|null $status
 */
#[Fillable([
    'whatsapp_message_id',
    'direction',
    'whatsapp_phone',
    'payload',
    'status',
])]
class WhatsappMessageLog extends Model
{
    public const string DIRECTION_INBOUND = 'inbound';

    public const string DIRECTION_OUTBOUND = 'outbound';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
