<?php

namespace App\Services\SatGo\Handlers;

use App\Contracts\DocumentGeneratorHandler;
use App\Enums\DocumentTypeCode;
use App\Models\FiscalCredential;
use App\Services\SatGo\SatGoClient;
use App\Services\SatGo\SatGoDocument;
use App\Services\SatGo\SatGoException;

/**
 * Constancia de Situación Fiscal.
 */
class FiscalSituationHandler implements DocumentGeneratorHandler
{
    public function __construct(private readonly SatGoClient $client) {}

    public function code(): DocumentTypeCode
    {
        return DocumentTypeCode::FiscalSituation;
    }

    /**
     * @throws SatGoException
     */
    public function generate(FiscalCredential $credential): SatGoDocument
    {
        return $this->client->fiscalSituation($credential);
    }
}
