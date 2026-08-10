<?php

namespace App\Models\Concerns;

use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property-read Collection<int, FiscalDocument> $fiscalDocuments
 */
trait HasFiscalDocuments
{
    /**
     * Fiscal documents owned by this taxpayer.
     *
     * @return MorphMany<FiscalDocument, $this>
     */
    public function fiscalDocuments(): MorphMany
    {
        return $this->morphMany(FiscalDocument::class, 'fiscalSubject');
    }
}
