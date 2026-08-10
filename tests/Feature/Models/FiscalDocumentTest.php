<?php

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a company can retrieve its own and its users fiscal documents', function () {
    $company = Company::factory()->create();
    $companyUser = User::factory()->for($company)->create();
    $otherCompanyUser = User::factory()->for(Company::factory())->create();

    $companyDocument = FiscalDocument::factory()->for($company, 'fiscalSubject')->create();
    $userDocument = FiscalDocument::factory()->for($companyUser, 'fiscalSubject')->create();
    $otherUserDocument = FiscalDocument::factory()->for($otherCompanyUser, 'fiscalSubject')->create();

    expect($company->retrievableFiscalDocuments()->pluck('id')->all())
        ->toContain($companyDocument->id, $userDocument->id)
        ->not->toContain($otherUserDocument->id);
});

test('a fiscal document belongs to its taxpayer and records the requester', function () {
    $requester = User::factory()->create();
    $subject = User::factory()->create();

    $document = FiscalDocument::factory()
        ->for($subject, 'fiscalSubject')
        ->for($requester, 'requestedBy')
        ->create([
            'type' => FiscalDocumentType::TaxStatusCertificate,
            'status' => FiscalDocumentStatus::Completed,
            'disk' => null,
        ]);

    expect($document->uuid)->not->toBeNull()
        ->and($document->disk)->toBe(FiscalDocument::STORAGE_DISK)
        ->and($document->fiscalSubject->is($subject))->toBeTrue()
        ->and($document->requestedBy->is($requester))->toBeTrue()
        ->and($document->type)->toBe(FiscalDocumentType::TaxStatusCertificate)
        ->and($document->status)->toBe(FiscalDocumentStatus::Completed);
});

test('a user can only retrieve a fiscal document they own', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();
    $colleague = User::factory()->for($company)->create();

    $ownDocument = FiscalDocument::factory()->for($user, 'fiscalSubject')->create();
    $colleagueDocument = FiscalDocument::factory()->for($colleague, 'fiscalSubject')->create();
    $companyDocument = FiscalDocument::factory()->for($company, 'fiscalSubject')->create();

    expect($user->canRetrieveFiscalDocument($ownDocument))->toBeTrue()
        ->and($user->canRetrieveFiscalDocument($colleagueDocument))->toBeFalse()
        ->and($user->canRetrieveFiscalDocument($companyDocument))->toBeFalse()
        ->and($user->can('view', $ownDocument))->toBeTrue()
        ->and($user->can('view', $colleagueDocument))->toBeFalse()
        ->and($user->can('view', $companyDocument))->toBeFalse();
});
