<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The outcome of the latest attempt to retrieve a document.
 *
 * Deliverability is `Completed` plus a stored file. A refresh that fails while a
 * copy from earlier the same day is still on disk deliberately stays `Completed`,
 * with the failure recorded on the row.
 */
enum FiscalDocumentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Filament reads labels and colours through these contracts, so selects and
     * badges pick them up without repeating a closure per resource.
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Processing => 'En proceso',
            self::Completed => 'Completado',
            self::Failed => 'Fallido',
        };
    }

    /**
     * Colour used by Filament badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
