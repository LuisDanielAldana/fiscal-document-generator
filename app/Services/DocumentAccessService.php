<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The one place that answers "whose documents may this account reach?".
 *
 * The bot uses it to decide which taxpayers to offer, and the panel uses it to
 * scope its tables. Keeping both on this service is what stops the WhatsApp flow
 * and the panel from drifting apart on a question that is the whole point of the
 * authorization model. FiscalDocumentPolicy enforces the same rule per record.
 */
class DocumentAccessService
{
    /**
     * The taxpayers this account may request documents for.
     *
     * Returns the subjects themselves — a Company or a User — because that is what
     * a document is filed against. Only taxpayers with a usable credential are
     * offered: there is nothing to fetch for the others.
     *
     * @return Collection<int, Company|User>
     */
    public function accessibleSubjects(User $user): Collection
    {
        return $this->accessibleCredentials($user)
            ->map(fn (FiscalCredential $credential) => $credential->owner)
            ->filter()
            ->unique(fn (Model $subject) => $subject->getMorphClass().':'.$subject->getKey())
            ->values();
    }

    /**
     * The credentials behind those taxpayers.
     *
     * @return Collection<int, FiscalCredential>
     */
    public function accessibleCredentials(User $user): Collection
    {
        return $this->credentialsFor($user)
            ->usable()
            ->with('owner')
            ->orderBy('rfc')
            ->get();
    }

    /**
     * Every credential this account may see, as a query the panel can extend.
     *
     * Unlike `accessibleCredentials`, this does not filter to usable ones: an
     * expired certificate is exactly what an administrator opens the panel to fix.
     *
     * @return Builder<FiscalCredential>
     */
    public function credentialsFor(User $user): Builder
    {
        return FiscalCredential::query()
            ->where(fn (Builder $query) => $this->constrainToVisibleOwners($query, $user));
    }

    /**
     * Every document this account may see, as a query the panel can extend.
     *
     * @return Builder<FiscalDocument>
     */
    public function documentsFor(User $user): Builder
    {
        $query = FiscalDocument::query();

        if ($user->role === UserRole::SuperAdmin) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            // Always their own.
            $query->whereMorphedTo('fiscalSubject', $user);

            if (! $user->isOwnerOrAdmin() || $user->company_id === null) {
                return;
            }

            // Plus the company's, and its users'.
            $query
                ->orWhere(function (Builder $query) use ($user): void {
                    $query->where('fiscal_subject_type', (new Company)->getMorphClass())
                        ->where('fiscal_subject_id', $user->company_id);
                })
                ->orWhere(function (Builder $query) use ($user): void {
                    $query->where('fiscal_subject_type', (new User)->getMorphClass())
                        ->whereIn('fiscal_subject_id', User::query()
                            ->where('company_id', $user->company_id)
                            ->select('id'));
                });
        });
    }

    /**
     * The stored copy to send for a taxpayer and type, or null when there is
     * nothing fresh enough to send.
     *
     * Staleness is the type's own tolerance: handing somebody last month's
     * compliance opinion is worse than telling them to wait for a new one.
     */
    public function deliverableDocument(Model $subject, DocumentType $type): ?FiscalDocument
    {
        $document = FiscalDocument::query()
            ->whereMorphedTo('fiscalSubject', $subject)
            ->where('document_type_id', $type->id)
            ->deliverable()
            ->with('documentType')
            ->first();

        if ($document === null) {
            return null;
        }

        return $document->isStale() ? null : $document;
    }

    /**
     * The credential to fetch a taxpayer's documents with, when one is needed on
     * demand.
     */
    public function credentialFor(Model $subject): ?FiscalCredential
    {
        return FiscalCredential::query()
            ->usable()
            ->whereMorphedTo('owner', $subject)
            ->first();
    }

    /**
     * Restrict a credential query to the owners this account may see.
     *
     * @param  Builder<FiscalCredential>  $query
     */
    private function constrainToVisibleOwners(Builder $query, User $user): void
    {
        if ($user->role === UserRole::SuperAdmin) {
            return;
        }

        // Their own credential, as a natural person.
        $query->whereMorphedTo('owner', $user);

        if (! $user->isOwnerOrAdmin() || $user->company_id === null) {
            return;
        }

        $query
            ->orWhere(function (Builder $query) use ($user): void {
                $query->where('owner_type', (new Company)->getMorphClass())
                    ->where('owner_id', $user->company_id);
            })
            ->orWhere(function (Builder $query) use ($user): void {
                $query->where('owner_type', (new User)->getMorphClass())
                    ->whereIn('owner_id', User::query()
                        ->where('company_id', $user->company_id)
                        ->select('id'));
            });
    }
}
