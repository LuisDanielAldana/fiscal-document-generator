<?php

namespace Database\Factories;

use App\Enums\DocumentTypeCode;
use App\Enums\FiscalDocumentStatus;
use App\Models\DocumentType;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<FiscalDocument>
 */
class FiscalDocumentFactory extends Factory
{
    /**
     * A delivered document about a natural person, retrieved today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = now()->toDateString();

        return [
            'fiscal_subject_type' => (new User)->getMorphClass(),
            'fiscal_subject_id' => User::factory(),
            'document_type_id' => DocumentType::factory(),
            'status' => FiscalDocumentStatus::Completed,
            'source' => 'schedule',
            'disk' => FiscalDocument::STORAGE_DISK,
            'path' => 'rfc/'.fake()->uuid().'/'.$date.'.pdf',
            'original_filename' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(10_000, 1_000_000),
            'generated_for_date' => $date,
            'requested_at' => now()->subMinute(),
            'completed_at' => now(),
            'generated_at' => now(),
        ];
    }

    /**
     * A document about a given taxpayer — a Company or a User.
     */
    public function about(Model $subject): static
    {
        return $this->state(fn (array $attributes): array => [
            'fiscal_subject_type' => $subject->getMorphClass(),
            'fiscal_subject_id' => $subject->getKey(),
        ]);
    }

    public function ofType(DocumentTypeCode $code): static
    {
        return $this->for(DocumentType::factory()->code($code), 'documentType');
    }

    /**
     * Retrieved with a particular credential, which is what provenance means here.
     */
    public function using(FiscalCredential $credential): static
    {
        return $this->state(fn (array $attributes): array => [
            'fiscal_credential_id' => $credential->id,
        ])->about($credential->owner);
    }

    /**
     * Retrieved on an earlier day, which is how staleness is exercised.
     */
    public function generatedOn(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'generated_for_date' => $date,
            'path' => 'rfc/'.fake()->uuid().'/'.$date.'.pdf',
            'completed_at' => now()->parse($date)->setTime(2, 5),
            'generated_at' => now()->parse($date)->setTime(2, 5),
        ]);
    }

    /**
     * Nothing was retrieved: no file, and a reason.
     */
    public function failed(string $error = 'SatGo rejected the request.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FiscalDocumentStatus::Failed,
            'disk' => null,
            'path' => null,
            'original_filename' => null,
            'mime_type' => null,
            'size' => null,
            'error_message' => $error,
            'completed_at' => null,
            'generated_at' => null,
            'failed_at' => now(),
        ]);
    }

    /**
     * Already reported in a digest, so the next run must not repeat it.
     */
    public function alreadyReported(): static
    {
        return $this->state(fn (array $attributes): array => [
            'failure_notified_at' => now(),
        ]);
    }

    /**
     * Write a real file for this row on the (usually faked) private disk.
     */
    public function withStoredFile(string $contents = '%PDF-1.4 test'): static
    {
        return $this->afterCreating(function (FiscalDocument $document) use ($contents): void {
            if ($document->path !== null) {
                Storage::disk($document->disk ?? FiscalDocument::STORAGE_DISK)
                    ->put($document->path, $contents);
            }
        });
    }
}
