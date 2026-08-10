<?php

namespace App\Filament\Resources\FiscalCredentials\Pages;

use App\Filament\Resources\FiscalCredentials\FiscalCredentialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFiscalCredential extends CreateRecord
{
    protected static string $resource = FiscalCredentialResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        FiscalCredentialResource::assertUsable($data);

        return $data;
    }
}
