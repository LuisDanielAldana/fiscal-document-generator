<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Mail\DailyGenerationFailureReport;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the super admins one summary of everything that failed in a run.
 *
 * Dispatched from the batch's `finally` callback, so it runs once after every
 * document has either succeeded or exhausted its five attempts — one email per
 * night listing every problem, rather than a message per failure.
 *
 * Reporting is driven by `error_message` rather than by status, because a taxpayer
 * whose refresh failed while yesterday's copy is still deliverable stays
 * `Completed` and would otherwise go unmentioned.
 */
class ReportGenerationFailures implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $forDate,
        public ?string $batchId = null,
    ) {}

    public function handle(): void
    {
        $failures = $this->failures();

        if ($failures->isEmpty()) {
            Log::info('Daily document generation finished with no failures.', [
                'date' => $this->forDate,
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        $recipients = $this->superAdmins();

        // Always log the digest: if no super admin has an address, or mail is
        // misconfigured, the failures must still be recoverable from the logs.
        Log::error('Daily document generation finished with failures.', [
            'date' => $this->forDate,
            'batch_id' => $this->batchId,
            'failures' => $failures->count(),
            'recipients' => $recipients->count(),
            'rfcs' => $failures->map(fn (FiscalDocument $d) => $d->fiscalCredential?->rfc)->filter()->unique()->values()->all(),
        ]);

        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients->all())->send(
            new DailyGenerationFailureReport($this->forDate, $failures),
        );

        // Marked only after the mail is handed off, so a failure to send leaves
        // these to be reported by the next run rather than swallowing them.
        FiscalDocument::query()
            ->whereKey($failures->modelKeys())
            ->update(['failure_notified_at' => now()]);
    }

    /**
     * Everything that went wrong on this date and has not been reported yet.
     *
     * @return Collection<int, FiscalDocument>
     */
    private function failures(): Collection
    {
        return FiscalDocument::query()
            ->whereDate('generated_for_date', $this->forDate)
            ->whereNotNull('error_message')
            ->whereNull('failure_notified_at')
            ->with(['documentType', 'fiscalCredential.owner', 'fiscalSubject'])
            ->orderBy('fiscal_credential_id')
            ->get();
    }

    /**
     * The people who get told. Super admins are the only role that sees across
     * every company, which is what a run-wide digest reports on.
     *
     * @return Collection<int, User>
     */
    private function superAdmins(): Collection
    {
        return User::query()
            ->where('role', UserRole::SuperAdmin)
            ->whereNotNull('email')
            ->get();
    }
}
