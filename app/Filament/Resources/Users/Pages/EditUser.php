<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // Deleting yourself would end the session mid-request and leave a
                // company without the administrator who was doing the work.
                ->visible(fn (): bool => ! $this->getRecord()->is(auth()->user())),
        ];
    }
}
