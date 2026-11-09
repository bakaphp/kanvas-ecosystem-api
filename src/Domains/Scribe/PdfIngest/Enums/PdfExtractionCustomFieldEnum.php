<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Enums;

/**
 * What the classifier read off a file, kept on the Filesystem row itself so a later write tool can take the
 * addressee and payment hints from the document rather than from the model's retelling of it: an agent once
 * passed its own company as the bill-to on an invoice addressed to a person, and the addressee check had
 * nothing else to compare against.
 */
enum PdfExtractionCustomFieldEnum: string
{
    case EXTRACTED = 'scribe_pdf_extracted';
}
