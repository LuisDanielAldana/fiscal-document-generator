<?php

namespace App\Jobs;

use App\Services\Whatsapp\WhatsAppBotService;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Handles one inbound WhatsApp message away from the request.
 *
 * Meta expects a 200 within seconds and retries when it does not get one, so the
 * webhook only records the message and queues this. Anything slow — reaching the
 * SAT, uploading a PDF — happens here.
 */
class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $message  The `messages[0]` object from Meta.
     */
    public function __construct(public array $message) {}

    public function handle(WhatsAppBotService $bot): void
    {
        $bot->handleInboundMessage($this->message);
    }

    /**
     * Say something once the retries are gone.
     *
     * Silence reads as a broken service to the person waiting, so the last act of
     * a failed message is to admit it.
     */
    public function failed(Throwable $e): void
    {
        $phone = $this->message['from'] ?? null;

        if (! is_string($phone) || $phone === '') {
            return;
        }

        try {
            app(WhatsAppClient::class)->sendText(
                $phone,
                'Tuve un problema al procesar tu mensaje. Vuelve a intentarlo en unos minutos.',
            );
        } catch (Throwable) {
            // Meta is unreachable too. The original failure is already reported.
        }
    }
}
