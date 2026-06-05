<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Enums;

enum CustomFieldEnum: string
{
    case PASO_RAPIDO_PAYMENT_STATUS = 'paso_rapido_payment_status';
    case PASO_RAPIDO_PAYMENT_RESPONSE = 'paso_rapido_payment_response';
    case PASO_RAPIDO_DNI = 'paso_rapido_dni';
    case PASO_RAPIDO_RETRY_COUNT = 'paso_rapido_retry_count';
    case PASO_RAPIDO_MAX_RETRIES = 'paso_rapido_max_retries';
    case PASO_RAPIDO_INVOICE_COMMERCIAL_NAME = 'paso_rapido_invoice_commercial_name';
    case PASO_RAPIDO_INVOICE_DOCUMENT = 'paso_rapido_invoice_document';
    case PASO_RAPIDO_INVOICE_FISCAL_CREDIT = 'paso_rapido_invoice_fiscal_credit';
    case PASO_RAPIDO_INVOICE_NCF = 'paso_rapido_invoice_ncf';
    case PASO_RAPIDO_INVOICE_PDF = 'paso_rapido_invoice_pdf';
    case PASO_RAPIDO_INVOICE_REFERENCE = 'paso_rapido_invoice_reference';
}
