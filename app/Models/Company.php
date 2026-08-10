<?php

namespace App\Models;

use App\Models\Concerns\HasFiscalDocuments;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property string $name
 * @property string $legal_name
 * @property string $rfc
 * @property bool $is_active
 * @property string|null $fiscal_postal_code
 * @property string|null $tax_regime_code
 * @property string|null $tax_regime_name
 * @property string|null $email
 * @property string|null $phone_number
 * @property-read FiscalCredential|null $fiscalCredential
 */
#[Fillable([
    'name',
    'legal_name', // razón social
    'rfc',
    'is_active',
    'fiscal_postal_code',
    'tax_regime_code',
    'tax_regime_name',
    'email',
    'phone_number',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasFiscalDocuments;

    /**
     * The people who belong to this company.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The company's own e-signature, as a persona moral.
     *
     * @return MorphOne<FiscalCredential, $this>
     */
    public function fiscalCredential(): MorphOne
    {
        return $this->morphOne(FiscalCredential::class, 'owner');
    }

    /**
     * @return MorphOne<Address, $this>
     */
    public function address(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable');
    }

    /**
     * Documents this company is allowed to retrieve: its own and those owned by
     * its current users. Documents from other companies are excluded.
     *
     * The two alternatives are wrapped in a single group so that callers can
     * safely chain further constraints — an ungrouped `orWhere` here would turn
     * a later `->where(...)` into `own OR (user AND later)`.
     *
     * @return Builder<FiscalDocument>
     */
    public function retrievableFiscalDocuments(): Builder
    {
        return FiscalDocument::query()->where(function (Builder $query): void {
            $query
                ->whereMorphedTo('fiscalSubject', $this)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('fiscal_subject_type', (new User)->getMorphClass())
                        ->whereIn('fiscal_subject_id', $this->users()->select('users.id'));
                });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
