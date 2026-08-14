<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Enums;

enum ConfigurationEnum: string
{
    /** The Google service-account key (raw JSON string), set per app via $app->set(). Must have the Sheets API enabled on its project, and be shared as an Editor on every target sheet. */
    case GOOGLE_SHEETS_CREDENTIALS = 'google-sheets-credentials';

    /** The sheet URL/id the AP/AR agents log invoices to when a tool call doesn't specify one. */
    case DEFAULT_INVOICE_SHEET = 'google-sheets-default-invoice-tracker';
}
