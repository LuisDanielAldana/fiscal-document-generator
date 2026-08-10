<?php

namespace App\Contracts;

use App\Enums\DocumentTypeCode;
use App\Models\FiscalCredential;
use App\Services\SatGo\SatGoDocument;
use App\Services\SatGo\SatGoException;

/**
 * Knows how to fetch one kind of SAT document for a taxpayer.
 *
 * Adding a document type means writing a handler, adding a case to
 * DocumentTypeCode, and inserting a `document_types` row. Nothing reads a class
 * name out of the database; DocumentGeneratorRegistry maps the code to the
 * handler, so an unknown code fails loudly instead of instantiating whatever a
 * row happens to name.
 */
interface DocumentGeneratorHandler
{
    /**
     * The document type this handler is responsible for.
     */
    public function code(): DocumentTypeCode;

    /**
     * Retrieve the document, choosing FIEL or CIEC from the credential itself.
     *
     * @throws SatGoException when the SAT or SatGo does not return a document.
     */
    public function generate(FiscalCredential $credential): SatGoDocument;
}
