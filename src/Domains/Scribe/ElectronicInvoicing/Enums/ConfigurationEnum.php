<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum ConfigurationEnum: string
{
    // Company-level EcfProviderEnum value. Unset means the company does not issue e-CF and invoices keep their plain numbering.
    case ECF_PROVIDER = 'scribe-ecf-provider';

    // Company-level 'test' | 'production'. Defaults to test so a half-configured company never reaches DGII production.
    case ECF_ENVIRONMENT = 'scribe-ecf-environment';

    // Company-level EcfPdfSourceEnum value: whose legal PDF is attached to an accepted e-CF. Unset follows the provider's default.
    case ECF_PDF_SOURCE = 'scribe-ecf-pdf-source';

    // Company-level issuer RNC: required on every e-CF (DGII numbers e-NCFs per issuer) and printed on our own PDF.
    case ECF_ISSUER_TAX_ID = 'scribe-ecf-issuer-rnc';
}
