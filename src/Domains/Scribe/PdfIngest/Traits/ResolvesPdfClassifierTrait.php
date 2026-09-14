<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Traits;

use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;

/**
 * The container-bound classifier when something bound one (tests bind FakePdfClassifier), else Gemini.
 *
 * Shared because every consumer needs the same fallback and a divergent copy would silently ignore a
 * test's binding — the ingest action, the invoice extractor tool and the receipt extractor tool all
 * resolve through here.
 */
trait ResolvesPdfClassifierTrait
{
    protected function defaultPdfClassifier(): PdfClassifierServiceInterface
    {
        return app()->bound(PdfClassifierServiceInterface::class)
            ? app(PdfClassifierServiceInterface::class)
            : new GeminiPdfClassifierService();
    }
}
