<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Filesystem\Models\Filesystem;
use Spatie\LaravelData\Data;

/**
 * Input payload for ProcessAccountingPdfAction.
 *
 * Filesystem row must already exist (the webhook handler stores the inbound PDF before dispatching).
 * Inbound metadata is whatever the webhook handler captured (mailgun message_id, from email, subject).
 */
class PdfIngestInput extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Filesystem $pdf,
        public readonly ?string $messageId = null,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $subject = null,
        public readonly ?array $inboundMetadata = null,
    ) {
    }
}
