<?php

namespace App\Services\SatGo;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * A file retrieved from the SAT, before it is stored.
 *
 * SatGo names its downloads meaningfully (`{rfc}-constancia_situacion_fiscal.pdf`),
 * so the filename it sends is carried through to what the taxpayer eventually
 * receives on WhatsApp instead of being reinvented.
 */
readonly class SatGoDocument
{
    public function __construct(
        public string $contents,
        public string $filename,
        public string $mimeType = 'application/pdf',
    ) {}

    /**
     * Build from a SatGo response, falling back to a generated name when no
     * `Content-Disposition` is present.
     */
    public static function fromResponse(Response $response, string $fallbackName): self
    {
        return new self(
            contents: $response->body(),
            filename: static::filenameFrom($response) ?? $fallbackName,
            mimeType: Str::before($response->header('Content-Type') ?: 'application/pdf', ';'),
        );
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function extension(): string
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION) ?: 'pdf';
    }

    /**
     * The attachment filename SatGo advertised, if any.
     */
    private static function filenameFrom(Response $response): ?string
    {
        $disposition = $response->header('Content-Disposition');

        if ($disposition === '') {
            return null;
        }

        // filename="x.pdf", filename=x.pdf, and RFC 5987's filename*=UTF-8''x.pdf.
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches) !== 1) {
            return null;
        }

        $filename = basename(urldecode(trim($matches[1])));

        return $filename === '' ? null : $filename;
    }
}
