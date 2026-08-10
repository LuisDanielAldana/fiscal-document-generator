<?php

namespace App\Models;

use App\Enums\SatCredentialMethod;
use Carbon\CarbonInterface;
use Database\Factories\FiscalCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * How one RFC authenticates against the SAT, owned by a company or a user.
 *
 * A taxpayer may hold the FIEL, the CIEC, or both. `cer_path` and `key_path`
 * point at the private `fiscal-credentials` disk; the two passwords are
 * unrelated to each other and are encrypted at rest with APP_KEY via the
 * `encrypted` cast — note that rotating APP_KEY without re-encrypting these rows
 * makes every stored password unrecoverable.
 *
 * @property int $id
 * @property string $rfc
 * @property string|null $cer_path
 * @property string|null $key_path
 * @property string|null $fiel_password
 * @property string|null $ciec_password
 * @property SatCredentialMethod|null $preferred_method
 * @property CarbonInterface|null $fiel_expires_at
 * @property bool $is_active
 * @property CarbonInterface|null $last_validated_at
 * @property-read Company|User $owner
 */
#[Fillable([
    'rfc',
    'cer_path',
    'key_path',
    'fiel_password',
    'ciec_password',
    'preferred_method',
    'fiel_expires_at',
    'is_active',
    'last_validated_at',
])]
#[Hidden([
    'fiel_password',
    'ciec_password',
])]
class FiscalCredential extends Model
{
    /** @use HasFactory<FiscalCredentialFactory> */
    use HasFactory;

    /**
     * The private disk holding the e-signature files.
     */
    public const string STORAGE_DISK = 'fiscal-credentials';

    /**
     * The taxpayer this credential belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<FiscalDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /**
     * Credentials the nightly job should attempt.
     *
     * A row with neither mechanism complete is excluded here rather than left to
     * fail at the API, which keeps the nightly error digest to real problems.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    // A usable FIEL: both files, a passphrase, and still valid.
                    ->where(function (Builder $query): void {
                        $query->whereNotNull('cer_path')
                            ->whereNotNull('key_path')
                            ->whereNotNull('fiel_password')
                            ->where(function (Builder $query): void {
                                $query->whereNull('fiel_expires_at')
                                    ->orWhereDate('fiel_expires_at', '>=', now()->toDateString());
                            });
                    })
                    // Or a CIEC, which does not expire on a date we know of.
                    ->orWhereNotNull('ciec_password');
            });
    }

    /**
     * Whether the e-signature files and passphrase are all present.
     */
    public function hasFiel(): bool
    {
        return filled($this->cer_path)
            && filled($this->key_path)
            && filled($this->fiel_password);
    }

    /**
     * Whether the SAT portal password is on file.
     */
    public function hasCiec(): bool
    {
        return filled($this->ciec_password);
    }

    /**
     * The mechanism to authenticate this taxpayer's next request with, or null
     * when neither is usable.
     *
     * An expired certificate is not offered even when `preferred_method` asks for
     * it: the SAT rejects it, so falling back to the CIEC is the only way the
     * request can succeed.
     */
    public function method(): ?SatCredentialMethod
    {
        $fiel = $this->hasFiel() && ! $this->hasExpired();
        $ciec = $this->hasCiec();

        if ($this->preferred_method === SatCredentialMethod::Ciec && $ciec) {
            return SatCredentialMethod::Ciec;
        }

        return match (true) {
            $fiel => SatCredentialMethod::Fiel,
            $ciec => SatCredentialMethod::Ciec,
            default => null,
        };
    }

    /**
     * Whether a request can be made at all with what is on file.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->method() !== null;
    }

    /**
     * Whether the certificate is past its validity date.
     */
    public function hasExpired(): bool
    {
        return $this->fiel_expires_at !== null
            && $this->fiel_expires_at->startOfDay()->isBefore(now()->startOfDay());
    }

    /**
     * How the owner should be described in an operator-facing message.
     */
    public function ownerLabel(): string
    {
        return match (true) {
            $this->owner instanceof Company => $this->owner->name,
            $this->owner instanceof User => $this->owner->name,
            default => $this->rfc,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiel_password' => 'encrypted',
            'ciec_password' => 'encrypted',
            'preferred_method' => SatCredentialMethod::class,
            'fiel_expires_at' => 'date',
            'is_active' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }
}
