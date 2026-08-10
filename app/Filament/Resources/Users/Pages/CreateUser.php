<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * An administrator creates people inside their own company.
     *
     * The company select is hidden from them, so it is pinned here rather than
     * trusted from the form — otherwise a crafted request could plant a user in
     * another tenant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user?->role !== UserRole::SuperAdmin) {
            $data['company_id'] = $user?->company_id;
        }

        return $data;
    }
}
