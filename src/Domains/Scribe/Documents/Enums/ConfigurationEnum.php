<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Documents\Enums;

enum ConfigurationEnum: string
{
    // App-level name of a stored template used to render invoice PDFs. Falls back to the packaged layout when unset.
    case INVOICE_PDF_TEMPLATE = 'invoice-pdf-template';

    // App-level name of a stored template used to render quote PDFs. Falls back to the packaged layout when unset.
    case QUOTE_PDF_TEMPLATE = 'quote-pdf-template';
}
