<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasFiscalDocuments;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property int|null $company_id
 * @property UserRole $role
 * @property string $email
 * @property string $password
 * @property string|null $rfc
 * @property string|null $fiscal_postal_code
 * @property string|null $tax_regime_code
 * @property string|null $tax_regime_name
 * @property string|null $whatsapp_number
 * @property bool $whatsapp_verified
 * @property CarbonInterface|null $whatsapp_verified_at
 * @property-read Company|null $company
 * @property-read FiscalCredential|null $fiscalCredential
 */
#[Fillable([
    'name',
    'company_id',
    'role',
    'email',
    'password',
    'rfc',
    'fiscal_postal_code',
    'tax_regime_code',
    'tax_regime_name',
    'whatsapp_number',
    'whatsapp_verified',
    'whatsapp_verified_at',
])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasFiscalDocuments, Notifiable;

    /**
     * Determine whether this natural-person account may retrieve a document.
     *
     * Membership in a company does not by itself grant access to that company's
     * documents or to documents owned by its other users; that is what the
     * privileged roles are for, and the policy handles it.
     */
    public function canRetrieveFiscalDocument(FiscalDocument $document): bool
    {
        return $document->fiscalSubject instanceof self
            && $document->fiscalSubject->is($this);
    }

    /**
     * The user's own e-signature, when they file as a natural person.
     *
     * @return MorphOne<FiscalCredential, $this>
     */
    public function fiscalCredential(): MorphOne
    {
        return $this->morphOne(FiscalCredential::class, 'owner');
    }

    /**
     * The company the user belongs to.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return MorphOne<Address, $this>
     */
    public function address(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable');
    }

    /**
     * Whether this account administers its company, and so may see every
     * document belonging to the company and to the company's users.
     */
    public function isOwnerOrAdmin(): bool
    {
        return $this->role->isPrivileged();
    }

    /**
     * Users the bot can actually message.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeReachableOnWhatsApp(Builder $query): Builder
    {
        return $query->whereNotNull('whatsapp_number')
            ->where('whatsapp_verified', true);
    }

    /**
     * Only privileged roles reach the panel; a plain `user` interacts over
     * WhatsApp only. Filament grants access to every authenticated user unless
     * this contract is implemented, so it must stay in place.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isOwnerOrAdmin();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'whatsapp_verified' => 'boolean',
            'whatsapp_verified_at' => 'datetime',
        ];
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
