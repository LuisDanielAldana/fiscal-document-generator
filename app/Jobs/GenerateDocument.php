<?php

namespace App\Jobs;

use App\Enums\FiscalDocumentStatus;
use App\Models\DocumentType;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Services\SatGo\DocumentGeneratorRegistry;
use App\Services\SatGo\SatGoDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retrieves one document, for one taxpayer, for one day.
 *
 * The unique key on (credential, type, date) makes this idempotent: the nightly
 * run and a later on-demand request for the same day update one row rather than
 * accumulating attempts.
 *
 * A failure never deletes the file a previous attempt stored. When today's copy
 * already exists, a failed refresh is recorded on the row but the status stays
 * `Completed`, so the taxpayer can still be sent the document while the problem
 * is looked at. The nightly digest reads `error_message`, not `status`, precisely
 * so those cases are still reported.
 */
class GenerateDocument implements ShouldQueue
{
    use Queueable;

    /**
     * Five attempts, as required: the SAT is unreliable enough that a single
     * refusal says little.
     */
    public int $tries = 5;

    /**
     * Waits between the five attempts. They lengthen because SAT outages are
     * usually minutes long, not seconds.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 1800];

    /**
     * Must exceed the HTTP timeout in `services.satgo.timeout`, or the worker
     * would kill a request that was still legitimately waiting on the SAT.
     */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $fiscalCredentialId,
        public int $documentTypeId,
        public string $forDate,
        /** schedule | whatsapp | manual */
        public string $source = 'schedule',
        public ?int $requestedByUserId = null,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(DocumentGeneratorRegistry $registry): void
    {
        try {
            $credential = FiscalCredential::query()->findOrFail($this->fiscalCredentialId);
            $type = DocumentType::query()->findOrFail($this->documentTypeId);
        } catch (ModelNotFoundException) {
            // Deleted between dispatch and execution. Not an operator-actionable
            // SAT problem, so it is logged and dropped rather than retried.
            Log::info('Skipping document generation for a record that no longer exists.', [
                'fiscal_credential_id' => $this->fiscalCredentialId,
                'document_type_id' => $this->documentTypeId,
            ]);

            $this->delete();

            return;
        }

        $document = $this->documentFor($credential, $type);

        try {
            $retrieved = $registry->forType($type)->generate($credential);

            $this->store($document, $credential, $type, $retrieved);
        } catch (Throwable $e) {
            $this->recordFailure($document, $e);

            throw $e;
        }
    }

    /**
     * Mark the final attempt, once the queue has given up.
     *
     * The row keeps a file it already had, because an undeliverable document is
     * worse than a stale one.
     */
    public function failed(Throwable $e): void
    {
        $document = FiscalDocument::query()
            ->where('fiscal_credential_id', $this->fiscalCredentialId)
            ->where('document_type_id', $this->documentTypeId)
            ->whereDate('generated_for_date', $this->forDate)
            ->first();

        if ($document === null) {
            return;
        }

        $document->update([
            'status' => $document->hasFile() ? $document->status : FiscalDocumentStatus::Failed,
            'error_message' => $e->getMessage(),
            'failed_at' => now(),
        ]);
    }

    /**
     * The row for this taxpayer, type and day, marked as being worked on.
     */
    private function documentFor(FiscalCredential $credential, DocumentType $type): FiscalDocument
    {
        $owner = $credential->owner;

        return FiscalDocument::updateOrCreate(
            [
                'fiscal_credential_id' => $credential->id,
                'document_type_id' => $type->id,
                'generated_for_date' => $this->forDate,
            ],
            [
                // The authorization anchor: who the document is about, which is the
                // credential's owner rather than whoever asked for it.
                'fiscal_subject_type' => $owner->getMorphClass(),
                'fiscal_subject_id' => $owner->getKey(),
                'status' => FiscalDocumentStatus::Processing,
                'source' => $this->source,
                'requested_by_user_id' => $this->requestedByUserId,
                'requested_at' => now(),
            ],
        );
    }

    /**
     * Persist the retrieved file and mark the row deliverable.
     */
    private function store(
        FiscalDocument $document,
        FiscalCredential $credential,
        DocumentType $type,
        SatGoDocument $retrieved,
    ): void {
        $path = $this->pathFor($credential, $type, $retrieved);

        Storage::disk(FiscalDocument::STORAGE_DISK)->put($path, $retrieved->contents);

        $document->update([
            'status' => FiscalDocumentStatus::Completed,
            'disk' => FiscalDocument::STORAGE_DISK,
            'path' => $path,
            'original_filename' => $retrieved->filename,
            'mime_type' => $retrieved->mimeType,
            'size' => $retrieved->size(),
            'generated_at' => now(),
            'completed_at' => now(),
            // A success clears the previous complaint, including one already
            // reported, so the next digest does not repeat yesterday's news.
            'error_message' => null,
            'failed_at' => null,
            'failure_notified_at' => null,
        ]);
    }

    /**
     * Record why an attempt failed, without touching a file already stored.
     */
    private function recordFailure(FiscalDocument $document, Throwable $e): void
    {
        $document->update([
            'status' => $document->hasFile() ? $document->status : FiscalDocumentStatus::Failed,
            'error_message' => $e->getMessage(),
            'failed_at' => now(),
        ]);

        report($e);
    }

    /**
     * Where the file lives on the private disk.
     *
     * Keyed by RFC, type and date so an operator can find a taxpayer's history by
     * looking, and so a re-run overwrites the day it belongs to.
     */
    private function pathFor(FiscalCredential $credential, DocumentType $type, SatGoDocument $retrieved): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            Str::lower($credential->rfc),
            $type->code->value,
            $this->forDate,
            $retrieved->extension(),
        );
    }
}
