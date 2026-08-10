<?php

namespace App\Services\SatGo;

use App\Models\FiscalCredential;
use RuntimeException;
use Throwable;

/**
 * A request to SatGo, or to the SAT behind it, did not produce a document.
 *
 * The message is what an operator reads in the nightly failure digest, so it
 * names the RFC and keeps SatGo's own explanation — which is usually the useful
 * part ("certificado vencido", "contraseña incorrecta") — while staying short
 * enough to sit in an email table.
 */
class SatGoException extends RuntimeException
{
    /**
     * How much of a SatGo error body to keep. Long enough for its message,
     * short enough that an HTML error page cannot flood the digest or the
     * `error_message` column.
     */
    private const int BODY_LIMIT = 500;

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $rfc = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * SatGo answered, but not with a document.
     */
    public static function requestFailed(string $rfc, int $status, string $body): self
    {
        return new self(
            sprintf('SatGo rejected the request for %s (HTTP %d): %s', $rfc, $status, self::summarize($body)),
            $status,
            $rfc,
        );
    }

    /**
     * A 2xx response that is not a PDF. SatGo occasionally reports a SAT-side
     * problem in the body while still returning a success status, and storing
     * that as a document would hand the taxpayer a corrupt file.
     */
    public static function notAPdf(string $rfc, string $body): self
    {
        return new self(
            sprintf('SatGo returned a non-PDF response for %s: %s', $rfc, self::summarize($body)),
            0,
            $rfc,
        );
    }

    /**
     * Neither the FIEL nor the CIEC is complete enough to attempt a request.
     */
    public static function unusableCredential(FiscalCredential $credential): self
    {
        return new self(
            sprintf(
                'No usable credential for %s: the FIEL is incomplete or expired and no CIEC is on file.',
                $credential->rfc,
            ),
            0,
            $credential->rfc,
        );
    }

    /**
     * An e-signature file is recorded but missing from the private disk.
     */
    public static function missingKeyMaterial(FiscalCredential $credential, string $path): self
    {
        return new self(
            sprintf('The e-signature file for %s is missing from storage: %s', $credential->rfc, $path),
            0,
            $credential->rfc,
        );
    }

    /**
     * The API key could not be exchanged for a token, so nothing can be fetched.
     */
    public static function authenticationFailed(int $status, string $body): self
    {
        return new self(
            sprintf('SatGo refused to issue a token (HTTP %d): %s', $status, self::summarize($body)),
            $status,
        );
    }

    /**
     * Collapse a response body to one readable line.
     */
    private static function summarize(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        if ($body === '') {
            return '(empty response)';
        }

        return mb_strimwidth($body, 0, self::BODY_LIMIT, '…');
    }
}
