<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\WhatsappMessageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Meta's webhook: subscription handshake, then inbound messages.
 *
 * Handling here is deliberately thin. Meta wants a 200 within seconds and retries
 * the same delivery when it does not get one, so this records what arrived and
 * queues the work. The unique message id is what makes a retry harmless.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * The one-time subscription handshake.
     *
     * Meta sends these as `hub.mode`, `hub.verify_token` and `hub.challenge`; PHP
     * turns the dots into underscores in `$_GET`, which is why the draft's
     * `hub_verify_token` looked wrong but was not — reading through `query()` keeps
     * that detail in one place.
     */
    public function verify(Request $request): Response
    {
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        $expected = (string) config('services.whatsapp.verify_token');

        if ($expected === '' || ! is_string($token) || ! hash_equals($expected, $token)) {
            return response('Invalid verify token', Response::HTTP_FORBIDDEN);
        }

        return response(is_string($challenge) ? $challenge : '', Response::HTTP_OK);
    }

    /**
     * Receive a batch of events.
     *
     * A single delivery can carry several messages across several entries, and
     * mixing in status updates (`delivered`, `read`) that carry no message at all.
     * Everything that is a message is queued; the rest is acknowledged and dropped.
     */
    public function handle(Request $request): JsonResponse
    {
        $queued = 0;

        foreach ($this->messagesIn($request) as $message) {
            if ($this->record($message)) {
                ProcessWhatsAppMessage::dispatch($message);
                $queued++;
            }
        }

        return response()->json(['status' => 'ok', 'queued' => $queued]);
    }

    /**
     * Every inbound message in the payload, in arrival order.
     *
     * @return list<array<string, mixed>>
     */
    private function messagesIn(Request $request): array
    {
        $messages = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['messages'] ?? []) as $message) {
                    if (is_array($message) && isset($message['id'], $message['from'])) {
                        $messages[] = $message;
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Log the message, and report whether it is new.
     *
     * The insert is the idempotency check: relying on a separate `exists()` would
     * leave a window in which two concurrent retries both pass it and the sender
     * gets answered twice. The unique index decides, once.
     *
     * @param  array<string, mixed>  $message
     */
    private function record(array $message): bool
    {
        try {
            WhatsappMessageLog::create([
                'whatsapp_message_id' => $message['id'],
                'direction' => WhatsappMessageLog::DIRECTION_INBOUND,
                'whatsapp_phone' => $message['from'],
                'payload' => $message,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        } catch (\Illuminate\Database\QueryException $e) {
            // Older drivers report the same condition as a plain query exception.
            if (DB::connection()->getName() !== null && str_contains($e->getMessage(), 'UNIQUE')) {
                return false;
            }

            throw $e;
        }

        return true;
    }
}
