<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto\Actions;

use Kanvas\Connectors\SalesAssist\DataTransferObject\TradeIn;
use Kanvas\Connectors\VAuto\Client;
use Kanvas\Connectors\VAuto\DataTransferObject\Appraisal;
use Kanvas\Connectors\VAuto\Enums\CustomFieldEnum;
use Kanvas\Connectors\VAuto\Exceptions\TradeInNotAppraisableException;
use Kanvas\Connectors\VAuto\Exceptions\VAutoException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

class PushAppraisalAction
{
    public function __construct(
        protected Lead $lead,
        protected Message $message,
        protected ?Client $client = null,
    ) {
    }

    /**
     * One appraisal per submitted trade-in message. The id is kept on the message, so a replayed
     * workflow does not open a second appraisal, and mirrored on the lead as its latest appraisal.
     */
    public function execute(): array
    {
        $existingId = $this->message->get(CustomFieldEnum::APPRAISAL_ID->value);

        if (! empty($existingId)) {
            return [
                'appraisal_id' => (string) $existingId,
                'deep_link' => (string) $this->message->get(CustomFieldEnum::APPRAISAL_DEEP_LINK->value),
                'skipped' => 'Appraisal already pushed for this message',
            ];
        }

        $form = TradeIn::fromMessageData((array) ($this->message->getMessage()['data'] ?? []));

        if (! $form->hasEnoughDetails()) {
            throw new TradeInNotAppraisableException('Trade-in has no VIN or year/make/model, nothing to appraise');
        }

        $client = $this->client ?? new Client($this->lead->company);

        $response = $client->createAppraisal(
            Appraisal::from($form, $this->message, $this->lead)->toVAutoArray($client->entityId(), $client->userName())
        );

        $result = $response->CreateAppraisalResult ?? null;
        $appraisalId = $result->AppraisalId ?? null;

        if (empty($appraisalId)) {
            throw new VAutoException('vAuto returned no AppraisalId');
        }

        $deepLink = (string) ($result->DeepLink ?? '');

        $this->message->set(CustomFieldEnum::APPRAISAL_ID->value, (string) $appraisalId);
        $this->message->set(CustomFieldEnum::APPRAISAL_DEEP_LINK->value, $deepLink);
        $this->lead->set(CustomFieldEnum::APPRAISAL_ID->value, (string) $appraisalId);
        $this->lead->set(CustomFieldEnum::APPRAISAL_DEEP_LINK->value, $deepLink);

        return [
            'appraisal_id' => (string) $appraisalId,
            'deep_link' => $deepLink,
        ];
    }
}
