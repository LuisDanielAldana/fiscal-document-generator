<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * The stable identifiers behind `document_types.code`.
 *
 * A row in `document_types` controls how a type is presented and whether it is
 * offered; this enum is how code recognises it. Adding a SAT document means
 * adding a case here, a handler, and a row — the handler class name is never
 * read from the database.
 */
enum DocumentTypeCode: string implements HasDescription, HasLabel
{
    /** Opinión de Cumplimiento de Obligaciones Fiscales. */
    case FiscalOpinion = 'fiscal_opinion';

    /** Constancia de Situación Fiscal. */
    case FiscalSituation = 'fiscal_situation';

    /**
     * Filament reads these through the contracts when building the type select.
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    public function label(): string
    {
        return match ($this) {
            self::FiscalOpinion => 'Opinión de Cumplimiento',
            self::FiscalSituation => 'Constancia de Situación Fiscal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FiscalOpinion => 'Opinión del cumplimiento de obligaciones fiscales emitida por el SAT.',
            self::FiscalSituation => 'Constancia con tus datos fiscales vigentes ante el SAT.',
        };
    }
}
