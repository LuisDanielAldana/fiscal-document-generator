<?php

namespace App\Filament\Resources\FiscalCredentials\Pages;

use App\Filament\Resources\FiscalCredentials\FiscalCredentialResource;
use App\Models\FiscalCredential;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFiscalCredential extends EditRecord
{
    protected static string $resource = FiscalCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var FiscalCredential $record */
        $record = $this->getRecord();

        FiscalCredentialResource::assertUsable($data, $record);

        return $data;
    }
}
