<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\User;

/**
 * Who may see a document.
 *
 * The rule is the business rule: a plain user sees only documents about
 * themselves; a company's legal representative or administrator sees everything
 * belonging to their company and to the company's users; a super admin sees
 * everything.
 *
 * The check reads `fiscal_subject` — who the document is about — and never the
 * credential that fetched it. The credential is provenance, is nullable, and can
 * be replaced or deleted without changing who the document concerns.
 */
class FiscalDocumentPolicy
{
    /**
     * Everyone may reach a document list; what it contains is scoped separately.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FiscalDocument $document): bool
    {
        if ($user->role === UserRole::SuperAdmin) {
            return true;
        }

        $subject = $document->fiscalSubject;

        // A natural person's document: theirs, or one of their administrators'.
        if ($subject instanceof User) {
            return $subject->is($user)
                || ($user->isOwnerOrAdmin() && $this->shareCompany($user, $subject));
        }

        // A company's own document: only the people who administer that company.
        if ($subject instanceof Company) {
            return $user->isOwnerOrAdmin()
                && $user->company_id !== null
                && $user->company_id === $subject->id;
        }

        return false;
    }

    /**
     * Whether the file behind a document may be sent to this user.
     *
     * Separate from `view` so a stale or fileless row can be listed in the panel
     * while still being refused as a download.
     */
    public function download(User $user, FiscalDocument $document): bool
    {
        return $document->hasFile() && $this->view($user, $document);
    }

    /**
     * Documents are produced by the SAT, not by people.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FiscalDocument $document): bool
    {
        return false;
    }

    /**
     * Only a super admin prunes history, since a compliance opinion is a
     * point-in-time record a company may later need to produce.
     */
    public function delete(User $user, FiscalDocument $document): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    /**
     * Two accounts belong to the same company.
     *
     * Both sides must actually have a company: two users with no company are not
     * colleagues, and treating null as a match would expose every unattached
     * taxpayer to every administrator.
     */
    private function shareCompany(User $user, User $other): bool
    {
        return $user->company_id !== null
            && $user->company_id === $other->company_id;
    }
}
