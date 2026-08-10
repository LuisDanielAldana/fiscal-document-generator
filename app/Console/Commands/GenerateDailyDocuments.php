<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDocument;
use App\Jobs\ReportGenerationFailures;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\FiscalCredential;
use App\Models\User;
use App\Services\SatGo\DocumentGeneratorRegistry;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Refreshes every taxpayer's documents for today.
 *
 * Runs at 02:00 Mexico City time so that a document is already waiting when
 * somebody asks for it over WhatsApp, rather than being fetched from the SAT
 * while they wait. Nothing is sent from here — the run only fills the cache.
 *
 * Failures are collected and reported once, by email, after every job has
 * settled; see ReportGenerationFailures.
 */
class GenerateDailyDocuments extends Command
{
    protected $signature = 'documents:generate-daily
                            {--date= : The fiscal day to generate for, defaults to today in Mexico City}';

    protected $description = 'Fetch today\'s fiscal documents for every active taxpayer';

    public function handle(DocumentGeneratorRegistry $registry): int
    {
        $date = $this->resolveDate();
        $types = $this->generatableTypes($registry);
        $credentials = $this->usableCredentials();

        if ($types->isEmpty()) {
            $this->components->warn('No active document types with a handler. Nothing to generate.');

            return self::SUCCESS;
        }

        if ($credentials->isEmpty()) {
            $this->components->warn('No active taxpayer has a usable FIEL or CIEC. Nothing to generate.');

            return self::SUCCESS;
        }

        $jobs = [];

        foreach ($credentials as $credential) {
            foreach ($types as $type) {
                $jobs[] = new GenerateDocument(
                    fiscalCredentialId: $credential->id,
                    documentTypeId: $type->id,
                    forDate: $date,
                );
            }
        }

        $this->dispatchBatch($jobs, $date);

        $this->components->info(sprintf(
            '%d document(s) queued for %s: %d taxpayer(s) × %d type(s).',
            count($jobs),
            $date,
            $credentials->count(),
            $types->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Queue the work, and report on it once every job has settled.
     *
     * `allowFailures()` is what lets one taxpayer's broken credential not cancel
     * everybody else's documents; `finally` then runs whether the batch ended
     * clean or not, which is what makes a single end-of-run digest possible.
     *
     * @param  list<GenerateDocument>  $jobs
     */
    private function dispatchBatch(array $jobs, string $date): void
    {
        Bus::batch($jobs)
            ->name("daily-documents-{$date}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($date): void {
                ReportGenerationFailures::dispatch($date, $batch->id);
            })
            ->dispatch();
    }

    /**
     * The day being generated for, in the application's Mexico City timezone.
     */
    private function resolveDate(): string
    {
        $date = $this->option('date');

        if (! is_string($date) || $date === '') {
            return now()->toDateString();
        }

        try {
            return now()->parse($date)->toDateString();
        } catch (Throwable) {
            $this->components->warn("Could not read --date=[{$date}], using today instead.");

            return now()->toDateString();
        }
    }

    /**
     * Offered types that some shipped handler can actually fetch.
     *
     * A type enabled in the panel before its handler shipped is called out here
     * rather than failing once per taxpayer for the rest of the night.
     *
     * @return Collection<int, DocumentType>
     */
    private function generatableTypes(DocumentGeneratorRegistry $registry): Collection
    {
        return DocumentType::query()
            ->offered()
            ->get()
            ->filter(function (DocumentType $type) use ($registry): bool {
                if ($registry->supports($type->code)) {
                    return true;
                }

                $this->components->warn("No handler generates [{$type->code->value}]; skipping it.");

                return false;
            })
            ->values();
    }

    /**
     * Every credential the run should attempt.
     *
     * A credential is included when it is usable in its own right — active, with a
     * complete FIEL that has not expired, or a CIEC — and when its owner is still
     * around and, for a company, still active.
     *
     * @return Collection<int, FiscalCredential>
     */
    private function usableCredentials(): Collection
    {
        return FiscalCredential::query()
            ->usable()
            ->with('owner')
            ->where(function (Builder $query): void {
                $query
                    ->whereHasMorph('owner', [Company::class], function (Builder $query): void {
                        $query->where('is_active', true);
                    })
                    ->orWhereHasMorph('owner', [User::class], function (Builder $query): void {
                        // Every user account is billable; there is no inactive flag
                        // on users, so belonging to the table is enough.
                    });
            })
            ->orderBy('rfc')
            ->get();
    }
}
