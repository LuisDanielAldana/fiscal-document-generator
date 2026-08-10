<?php

namespace Database\Factories;

use App\Enums\DocumentTypeCode;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    /**
     * Codes are handed out in order rather than at random: `code` is unique, and
     * a random pick makes any test that creates two types collide half the time.
     */
    protected static int $nextCode = 0;

    public function definition(): array
    {
        $cases = DocumentTypeCode::cases();
        $code = $cases[static::$nextCode++ % count($cases)];

        return [
            'code' => $code->value,
            'name' => $code->label(),
            'description' => $code->description(),
            'is_active' => true,
            'sort_order' => 0,
            'max_age_days' => 2,
        ];
    }

    public function code(DocumentTypeCode $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $code->value,
            'name' => $code->label(),
            'description' => $code->description(),
        ]);
    }

    public function fiscalOpinion(): static
    {
        return $this->code(DocumentTypeCode::FiscalOpinion);
    }

    public function fiscalSituation(): static
    {
        return $this->code(DocumentTypeCode::FiscalSituation);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
