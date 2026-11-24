<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Notifications\Enums;

/**
 * Twig rows in `email_templates`, seeded by ScribeEmailTemplateSeeder (apps_id 0) and SyncEmailTemplateAction
 * (per app). A company or app overrides one by saving a row under the same name.
 */
enum ScribeEmailTemplateEnum: string
{
    case ACCOUNTING_DIGEST = 'scribe-accounting-digest';
    case PAYMENT_RUN = 'scribe-payment-run';
    case DOCUMENT_DELIVERY = 'scribe-document-delivery';

    public function file(): string
    {
        return match ($this) {
            self::ACCOUNTING_DIGEST => 'views/emails/scribeAccountingDigest.twig',
            self::PAYMENT_RUN => 'views/emails/scribePaymentRun.twig',
            self::DOCUMENT_DELIVERY => 'views/emails/scribeDocumentDelivery.twig',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::ACCOUNTING_DIGEST => 'Accounting daily digest',
            self::PAYMENT_RUN => 'Payment run for the person who pays',
            self::DOCUMENT_DELIVERY => 'An invoice or quote sent to the customer',
        };
    }
}
