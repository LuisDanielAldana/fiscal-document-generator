<?php

namespace App\Models;

use App\Enums\DocumentTypeCode;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of document the bot can deliver.
 *
 * This row owns presentation and policy — label, ordering, whether it is offered,
 * and how stale a copy may be. The code that talks to SatGo is resolved from
 * `code` by the DocumentGeneratorRegistry, so no class name is stored here.
 *
 * @property int $id
 * @property DocumentTypeCode $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property int $max_age_days
 */
#[Fillable([
    'code',
    'name',
    'description',
    'is_active',
    'sort_order',
    'max_age_days',
])]
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<FiscalDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /**
     * Types currently offered, in the order they should be presented.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Cast, not just documented: a row whose code has no matching case is
            // a type that no shipped handler can fetch, and it should fail on
            // hydration rather than halfway through a nightly run.
            'code' => DocumentTypeCode::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'max_age_days' => 'integer',
        ];
    }
}
