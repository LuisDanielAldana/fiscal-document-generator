<?php

namespace App\Models;

use App\Enums\FiscalDocumentStatus;
use Carbon\CarbonInterface;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A PDF retrieved from the SAT for a natural person or a company.
 *
 * One row exists per taxpayer, document type and calendar day. A failed attempt
 * never clears the file left by an earlier successful one, so the last good copy
 * stays deliverable while the problem is investigated.
 *
 * @property int $id
 * @property string $uuid
 * @property int $document_type_id
 * @property int|null $fiscal_credential_id
 * @property int|null $requested_by_user_id
 * @property FiscalDocumentStatus $status
 * @property string|null $source
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size
 * @property string|null $error_message
 * @property CarbonInterface $generated_for_date
 * @property CarbonInterface|null $requested_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $generated_at
 * @property CarbonInterface|null $failure_notified_at
 * @property-read DocumentType $documentType
 * @property-read FiscalCredential|null $fiscalCredential
 */
#[Fillable([
    'uuid',
    'fiscal_subject_type',
    'fiscal_subject_id',
    'document_type_id',
    'fiscal_credential_id',
    'requested_by_user_id',
    'status',
    'source',
    'disk',
    'path',
    'original_filename',
    'mime_type',
    'size',
    'error_message',
    'generated_for_date',
    'requested_at',
    'completed_at',
    'failed_at',
    'expires_at',
    'generated_at',
    'failure_notified_at',
])]
#[Hidden(['error_message'])]
class FiscalDocument extends Model
{
    /** @use HasFactory<FiscalDocumentFactory> */
    use HasFactory;

    /**
     * The private disk holding generated documents.
     */
    public const string STORAGE_DISK = 'fiscal-documents';

    /**
     * The type of document.
     *
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * The e-signature used to retrieve this document.
     *
     * @return BelongsTo<FiscalCredential, $this>
     */
    public function fiscalCredential(): BelongsTo
    {
        return $this->belongsTo(FiscalCredential::class);
    }

    /**
     * The person who asked for this document, when it was not scheduled.
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * The taxpayer this document is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function fiscalSubject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Documents that were retrieved successfully.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', FiscalDocumentStatus::Completed);
    }

    /**
     * Documents holding a file we could actually send, newest first.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->completed()
            ->whereNotNull('path')
            ->orderByDesc('generated_for_date');
    }

    /**
     * Whether a stored file exists for this row.
     */
    public function hasFile(): bool
    {
        return filled($this->path);
    }

    /**
     * How many days old the retrieved document is.
     */
    public function ageInDays(): int
    {
        return (int) $this->generated_for_date
            ->startOfDay()
            ->diffInDays(now()->startOfDay());
    }

    /**
     * Whether this copy is too old to deliver, per its type's tolerance.
     *
     * Derived from the date the file was actually retrieved rather than a stored
     * counter, so repeated attempts within one day cannot skew it.
     */
    public function isStale(): bool
    {
        return $this->ageInDays() > $this->documentType->max_age_days;
    }

    /**
     * The filename to present to the recipient.
     */
    public function downloadFilename(): string
    {
        return $this->original_filename ?? sprintf(
            '%s-%s.pdf',
            Str::slug($this->documentType->name),
            $this->generated_for_date->toDateString(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FiscalDocumentStatus::class,
            'size' => 'integer',
            'generated_for_date' => 'date',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'generated_at' => 'datetime',
            'failure_notified_at' => 'datetime',
        ];
    }

    /**
     * Assign a public identifier and the storage disk before persisting.
     */
    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->uuid ??= (string) Str::uuid();
            $document->disk ??= self::STORAGE_DISK;
        });
    }
}
