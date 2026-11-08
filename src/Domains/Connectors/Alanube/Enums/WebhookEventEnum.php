<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Enums;

use Kanvas\Connectors\Alanube\Webhooks\AlanubeCancellationFinishedWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeCompanyStatusWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeDocumentReceivedWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeEmissionFinishedWebhookJob;

/**
 * The four webhooks in Alanube's company object, as `group.key` inside its `webhooks` block.
 */
enum WebhookEventEnum: string
{
    case EMISSION_FINISHED = 'documents.emissionFinished';
    case CANCELLATIONS = 'documents.cancellations';
    case RECEPTION = 'documents.reception';
    case GOVERNMENT_STATUS_CHANGED = 'general.governmentStatusChanged';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function key(): string
    {
        return explode('.', $this->value)[1];
    }

    /**
     * @return class-string
     */
    public function jobClass(): string
    {
        return match ($this) {
            self::EMISSION_FINISHED => AlanubeEmissionFinishedWebhookJob::class,
            self::CANCELLATIONS => AlanubeCancellationFinishedWebhookJob::class,
            self::RECEPTION => AlanubeDocumentReceivedWebhookJob::class,
            self::GOVERNMENT_STATUS_CHANGED => AlanubeCompanyStatusWebhookJob::class,
        };
    }

    public function receiverName(): string
    {
        return match ($this) {
            self::EMISSION_FINISHED => 'Alanube e-CF Emission Finished',
            self::CANCELLATIONS => 'Alanube e-NCF Cancellation Finished',
            self::RECEPTION => 'Alanube e-CF Received',
            self::GOVERNMENT_STATUS_CHANGED => 'Alanube DGII Company Status',
        };
    }
}
