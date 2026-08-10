<?php

namespace App\Services\SatGo;

use App\Enums\SatCredentialMethod;
use App\Models\FiscalCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Retrieves SAT documents through SatGo.
 *
 * Each document exists behind two endpoints, one per credential mechanism, and
 * they do not take the same shape: the FIEL route is a POST carrying the
 * certificate and private key as multipart form data (SatGo's "option B"), while
 * the CIEC route is a GET whose only secret is the `Secret` header. Both require
 * the `RFC` header. Which one is used is decided by the credential, not by the
 * caller — see FiscalCredential::method().
 *
 * The key material is streamed from the private disk straight into the request
 * body. It is deliberately never written to local disk: a `.key` left behind by
 * a crashed worker is a leaked e-signature.
 */
class SatGoClient
{
    public function __construct(private readonly SatGoAuthService $auth) {}

    /**
     * Opinión de Cumplimiento de Obligaciones Fiscales.
     *
     * @throws SatGoException
     */
    public function fiscalOpinion(FiscalCredential $credential): SatGoDocument
    {
        return $this->retrieve($credential, fielEndpoint: 'ocfiel', ciecEndpoint: 'oc');
    }

    /**
     * Constancia de Situación Fiscal.
     *
     * @throws SatGoException
     */
    public function fiscalSituation(FiscalCredential $credential): SatGoDocument
    {
        return $this->retrieve($credential, fielEndpoint: 'csffiel', ciecEndpoint: 'csf');
    }

    /**
     * Fetch a document using whichever mechanism this credential supports.
     *
     * @throws SatGoException
     */
    private function retrieve(FiscalCredential $credential, string $fielEndpoint, string $ciecEndpoint): SatGoDocument
    {
        $method = $credential->method();

        $response = match ($method) {
            SatCredentialMethod::Fiel => $this->postWithFiel($credential, $fielEndpoint),
            SatCredentialMethod::Ciec => $this->getWithCiec($credential, $ciecEndpoint),
            null => throw SatGoException::unusableCredential($credential),
        };

        return $this->pdfFrom($response, $credential, $method);
    }

    /**
     * SatGo option B: certificate and private key uploaded, passphrase alongside.
     *
     * @throws SatGoException
     */
    private function postWithFiel(FiscalCredential $credential, string $endpoint): Response
    {
        return $this->request($credential)
            ->attach('Certificado', $this->keyMaterial($credential, (string) $credential->cer_path), 'certificado.cer')
            ->attach('llavePrivada', $this->keyMaterial($credential, (string) $credential->key_path), 'llave.key')
            ->post($this->endpoint($endpoint), [
                'Contrasena' => $credential->fiel_password,
            ]);
    }

    /**
     * The CIEC travels as a header, so the request carries no body at all.
     *
     * @throws SatGoException
     */
    private function getWithCiec(FiscalCredential $credential, string $endpoint): Response
    {
        return $this->request($credential)
            ->withHeaders(['Secret' => $credential->ciec_password])
            ->get($this->endpoint($endpoint));
    }

    /**
     * A request authenticated as us and identified as this taxpayer.
     *
     * @throws SatGoException
     */
    private function request(FiscalCredential $credential): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->auth->token())
            ->withHeaders(['RFC' => $credential->rfc])
            ->timeout((int) config('services.satgo.timeout', 120));
    }

    /**
     * Read one of the e-signature files from the private disk.
     *
     * @throws SatGoException
     */
    private function keyMaterial(FiscalCredential $credential, string $path): string
    {
        $disk = Storage::disk(FiscalCredential::STORAGE_DISK);

        if ($path === '' || ! $disk->exists($path)) {
            throw SatGoException::missingKeyMaterial($credential, $path);
        }

        $contents = $disk->get($path);

        if ($contents === null || $contents === '') {
            throw SatGoException::missingKeyMaterial($credential, $path);
        }

        return $contents;
    }

    /**
     * Turn a response into a document, or explain why it is not one.
     *
     * An unauthorized answer also drops the cached token: it is usually a token
     * revoked ahead of its stated expiry, and every retry would otherwise present
     * the same dead credential.
     *
     * @throws SatGoException
     */
    private function pdfFrom(Response $response, FiscalCredential $credential, SatCredentialMethod $method): SatGoDocument
    {
        if ($response->unauthorized()) {
            $this->auth->forgetToken();
        }

        if ($response->failed()) {
            throw SatGoException::requestFailed($credential->rfc, $response->status(), $response->body());
        }

        $document = SatGoDocument::fromResponse($response, $this->fallbackFilename($credential, $method));

        // SatGo sometimes reports a SAT-side problem in a 2xx body. Storing that
        // would hand the taxpayer a file that is not a document.
        if (! str_starts_with($document->contents, '%PDF')) {
            throw SatGoException::notAPdf($credential->rfc, $response->body());
        }

        return $document;
    }

    /**
     * Used only when SatGo sends no `Content-Disposition`.
     */
    private function fallbackFilename(FiscalCredential $credential, SatCredentialMethod $method): string
    {
        return Str::lower($credential->rfc).'-'.$method->value.'-'.now()->toDateString().'.pdf';
    }

    /**
     * SatGo versions the consultar routes by path segment.
     */
    private function endpoint(string $name): string
    {
        return sprintf('/api/%s/consultar/%s', config('services.satgo.version', 'v2'), $name);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.satgo.base_url'), '/');
    }
}
