<?php

namespace Database\Seeders;

use App\Enums\DocumentTypeCode;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * The document types the application ships with.
 *
 * Nothing generates without these rows, so this seeder is required rather than
 * illustrative. It is idempotent: running it again updates the labels and leaves
 * whatever an operator has changed about ordering or staleness alone.
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach (DocumentTypeCode::cases() as $code) {
            DocumentType::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'name' => $code->label(),
                    'description' => $code->description(),
                    'is_active' => true,
                    'sort_order' => $order++,
                ],
            );
        }
    }
}
