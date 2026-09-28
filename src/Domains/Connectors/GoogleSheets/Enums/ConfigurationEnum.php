<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Enums;

enum ConfigurationEnum: string
{
    /** The Google service-account key (raw JSON string), set per app via $app->set(). Must have the Sheets API enabled on its project, and be shared as an Editor on every target sheet. */
    case GOOGLE_SHEETS_CREDENTIALS = 'google-sheets-credentials';

    /** The sheet URL/id the AP/AR agents log invoices to when a tool call doesn't specify one. */
    case DEFAULT_INVOICE_SHEET = 'google-sheets-default-invoice-tracker';

    /**
     * Optional: a real Workspace user's email to impersonate via domain-wide delegation, instead of
     * sharing every sheet with the service account directly. Requires a Workspace admin to authorize
     * the service account's OAuth Client ID for the Sheets scope under Admin Console > Security >
     * API Controls > Domain-wide Delegation. Use this when the tenant's Workspace blocks external
     * sharing (e.g. Trust Rules) and the impersonated user already has normal internal access.
     */
    case IMPERSONATE_USER = 'google-sheets-impersonate-user';
}
