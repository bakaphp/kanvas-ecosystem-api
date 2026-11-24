<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Support;

use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Services\DocumentFilesService;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * Signing a SOW is the commitment that bills (decision 8), so a quote that carries one is shared
 * through `sign-sow` and cannot be accepted without a signer.
 */
final class QuoteSignatureRule
{
    public static function requiresSignature(Quote $quote): bool
    {
        return $quote->sow_message_id !== null || new DocumentFilesService()->sowPdf($quote) !== null;
    }

    public static function actionFor(Quote $quote): DocumentActionEnum
    {
        return self::requiresSignature($quote) ? DocumentActionEnum::SIGN_SOW : DocumentActionEnum::VIEW_QUOTE;
    }
}
