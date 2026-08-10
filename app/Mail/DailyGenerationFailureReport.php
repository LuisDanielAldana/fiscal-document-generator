<?php

namespace App\Mail;

use App\Models\FiscalDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The end-of-run summary of every document the SAT would not give us.
 *
 * Written to be actionable from the inbox alone: each row names the taxpayer, the
 * document, and what SatGo said, because most causes — an expired certificate, a
 * changed CIEC — are fixed in the panel without anyone opening a log.
 */
class DailyGenerationFailureReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, FiscalDocument>  $failures
     */
    public function __construct(
        public string $forDate,
        public Collection $failures,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] %d fiscal document(s) failed to generate for %s',
                config('app.name'),
                $this->failures->count(),
                $this->forDate,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.fiscal-documents.generation-failures',
            with: [
                'forDate' => $this->forDate,
                'failures' => $this->failures,
            ],
        );
    }
}
