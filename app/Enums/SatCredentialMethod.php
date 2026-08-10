<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a request to the SAT is authenticated.
 *
 * The two mechanisms are not interchangeable at the transport level: the FIEL
 * uploads the certificate and private key as multipart form data, while the CIEC
 * travels as a `Secret` header on a GET. SatGo therefore exposes a separate
 * endpoint per mechanism for every document.
 */
enum SatCredentialMethod: string implements HasLabel
{
    /** The e-signature pair (.cer + .key + passphrase). */
    case Fiel = 'fiel';

    /** The SAT portal password. */
    case Ciec = 'ciec';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Fiel => 'FIEL (e.firma)',
            self::Ciec => 'CIEC (contraseña del SAT)',
        };
    }
}
