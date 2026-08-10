<?php

namespace App\Services\SatGo\Handlers;

use App\Contracts\DocumentGeneratorHandler;
use App\Enums\DocumentTypeCode;
use App\Models\FiscalCredential;
use App\Services\SatGo\SatGoClient;
use App\Services\SatGo\SatGoDocument;
use App\Services\SatGo\SatGoException;

/**
 * Opinión de Cumplimiento de Obligaciones Fiscales.
 */
class FiscalOpinionHandler implements DocumentGeneratorHandler
{
    public function __construct(private readonly SatGoClient $client) {}

    public function code(): DocumentTypeCode
    {
        return DocumentTypeCode::FiscalOpinion;
    }

    /**
     * @throws SatGoException
     */
    public function generate(FiscalCredential $credential): SatGoDocument
    {
        return $this->client->fiscalOpinion($credential);
    }
}
