<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    /** Unrestricted access across every company. */
    case SuperAdmin = 'super-admin';

    /** The company's owner (representante legal). */
    case LegalRepresentative = 'legal-representative';

    /** Manages a single company on the owner's behalf. */
    case Admin = 'admin';

    /** Consults only their own documents, over WhatsApp. */
    case User = 'user';

    /**
     * Roles that may administer a company: they see every document belonging to
     * the company and to its users, and they may reach the Filament panel.
     *
     * @return array<int, self>
     */
    public static function privileged(): array
    {
        return [self::SuperAdmin, self::LegalRepresentative, self::Admin];
    }

    public function isPrivileged(): bool
    {
        return in_array($this, self::privileged(), strict: true);
    }

    /**
     * Filament reads role labels through this contract when building selects.
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrador',
            self::LegalRepresentative => 'Representante legal',
            self::Admin => 'Administrador',
            self::User => 'Usuario',
        };
    }
}
