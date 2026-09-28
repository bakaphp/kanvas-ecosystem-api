<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Exceptions;

use RuntimeException;

/**
 * The file is not something the classifier can read, so it is refused before it reaches the model.
 * A person sending a Word file is not a fault — callers answer it rather than report it.
 */
class UnsupportedDocumentTypeException extends RuntimeException
{
    public function __construct(
        public readonly string $mimeType,
    ) {
        parent::__construct(
            "Unsupported document type {$mimeType}: only a PDF or a JPEG, PNG, WebP or HEIC photo can be read."
        );
    }
}
