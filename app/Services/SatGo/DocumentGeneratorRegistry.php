<?php

namespace App\Services\SatGo;

use App\Contracts\DocumentGeneratorHandler;
use App\Enums\DocumentTypeCode;
use App\Models\DocumentType;
use App\Services\SatGo\Handlers\FiscalOpinionHandler;
use App\Services\SatGo\Handlers\FiscalSituationHandler;
use RuntimeException;

/**
 * Resolves the handler that knows how to fetch a given document type.
 *
 * This is the seam that keeps `document_types` a presentation table: a row says
 * what to call a document and whether to offer it, and this class says which code
 * fetches it. Handler class names are never stored in the database, so a row can
 * never point at an arbitrary class.
 */
class DocumentGeneratorRegistry
{
    /**
     * Every handler the application ships with.
     *
     * @var list<class-string<DocumentGeneratorHandler>>
     */
    private const array HANDLERS = [
        FiscalOpinionHandler::class,
        FiscalSituationHandler::class,
    ];

    /**
     * @var array<string, DocumentGeneratorHandler>|null
     */
    private ?array $handlers = null;

    /**
     * The handler for a document type code.
     */
    public function for(DocumentTypeCode $code): DocumentGeneratorHandler
    {
        $handler = $this->handlers()[$code->value] ?? null;

        if (! $handler instanceof DocumentGeneratorHandler) {
            throw new RuntimeException("No document generator handles the code [{$code->value}].");
        }

        return $handler;
    }

    /**
     * The handler for a `document_types` row.
     *
     * A row whose code has no handler is a deployment mistake — a type enabled in
     * the panel before its code shipped — and must not be silently skipped, or the
     * nightly run would quietly produce nothing for it.
     */
    public function forType(DocumentType $type): DocumentGeneratorHandler
    {
        return $this->for($type->code);
    }

    /**
     * Whether a code can be generated at all.
     */
    public function supports(DocumentTypeCode $code): bool
    {
        return isset($this->handlers()[$code->value]);
    }

    /**
     * @return array<string, DocumentGeneratorHandler>
     */
    private function handlers(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }

        $handlers = [];

        foreach (self::HANDLERS as $class) {
            /** @var DocumentGeneratorHandler $handler */
            $handler = app($class);
            $handlers[$handler->code()->value] = $handler;
        }

        return $this->handlers = $handlers;
    }
}
