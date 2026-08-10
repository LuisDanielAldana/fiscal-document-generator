<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappMessageLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends messages through the WhatsApp Cloud API.
 *
 * Documents are uploaded to Meta's media endpoint and sent by id, never by link.
 * A link would mean putting a fiscal document behind a URL — signed and
 * short-lived, but still a URL that leaves our control and has to be reachable
 * from the public internet. Uploading keeps the bytes on paths we own.
 *
 * Every outbound message is logged, so the audit trail covers both directions.
 */
class WhatsAppClient
{
    /**
     * Meta's ceiling on reply buttons in one interactive message.
     */
    public const int MAX_BUTTONS = 3;

    /**
     * Meta's ceiling on rows in one interactive list.
     */
    public const int MAX_LIST_ROWS = 10;

    public function sendText(string $to, string $body): array
    {
        return $this->send($to, [
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    /**
     * Up to three reply buttons. Titles are truncated to Meta's 20-character
     * limit, which the whole message is rejected for exceeding.
     *
     * @param  list<array{id: string, title: string}>  $buttons
     */
    public function sendButtons(string $to, string $body, array $buttons): array
    {
        $buttons = array_slice($buttons, 0, self::MAX_BUTTONS);

        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => array_map(fn (array $button): array => [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $button['id'],
                            'title' => Str::limit($button['title'], 20, ''),
                        ],
                    ], $buttons),
                ],
            ],
        ]);
    }

    /**
     * A single-select list, for when there are more options than buttons allow.
     *
     * @param  list<array{id: string, title: string, description?: string}>  $rows
     */
    public function sendList(string $to, string $body, string $buttonLabel, array $rows): array
    {
        $rows = array_slice($rows, 0, self::MAX_LIST_ROWS);

        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => Str::limit($buttonLabel, 20, ''),
                    'sections' => [[
                        'title' => Str::limit($buttonLabel, 24, ''),
                        'rows' => array_map(fn (array $row): array => array_filter([
                            'id' => $row['id'],
                            'title' => Str::limit($row['title'], 24, ''),
                            'description' => isset($row['description'])
                                ? Str::limit($row['description'], 72)
                                : null,
                        ]), $rows),
                    ]],
                ],
            ],
        ]);
    }

    /**
     * Upload a file and send it, in the two steps Meta requires.
     */
    public function sendDocument(string $to, string $contents, string $filename, ?string $caption = null): array
    {
        $mediaId = $this->uploadDocument($contents, $filename);

        return $this->send($to, [
            'type' => 'document',
            'document' => array_filter([
                'id' => $mediaId,
                'filename' => $filename,
                'caption' => $caption,
            ]),
        ]);
    }

    /**
     * Hand a file to Meta, which holds it for 30 days and returns an id.
     */
    public function uploadDocument(string $contents, string $filename, string $mimeType = 'application/pdf'): string
    {
        $response = $this->http()
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post('/media', [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ])
            ->throw();

        return (string) $response->json('id');
    }

    /**
     * Post a message and record it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $to, array $payload): array
    {
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to] + $payload;

        $response = $this->http()->post('/messages', $payload);

        $this->log($to, $payload, $response);

        return $response->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(string $to, array $payload, Response $response): void
    {
        WhatsappMessageLog::create([
            'whatsapp_message_id' => $response->json('messages.0.id'),
            'direction' => WhatsappMessageLog::DIRECTION_OUTBOUND,
            'whatsapp_phone' => $to,
            'payload' => $payload,
            'status' => $response->successful() ? 'sent' : 'failed',
        ]);
    }

    private function http(): PendingRequest
    {
        $config = config('services.whatsapp');

        return Http::baseUrl(sprintf(
            'https://graph.facebook.com/%s/%s',
            $config['api_version'],
            $config['phone_number_id'],
        ))->withToken($config['access_token']);
    }
}
