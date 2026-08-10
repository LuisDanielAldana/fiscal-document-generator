<?php

namespace App\Filament\Resources\FiscalCredentials\Pages;

use App\Filament\Resources\FiscalCredentials\FiscalCredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFiscalCredentials extends ListRecords
{
    protected static string $resource = FiscalCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
