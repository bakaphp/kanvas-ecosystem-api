<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;

/**
 * Sends one document to every billing contact, one engagement each. A contact that cannot be reached
 * (no address, opted out) is reported and skipped; a refusal about the document itself (not accepted
 * by DGII, not set up) stops the whole send, since it holds for everyone.
 */
trait DeliversToBillingContacts
{
    /**
     * @param list<People> $recipients
     * @param callable(People): DocumentDelivery $send
     * @return array{recipients: list<array<string, mixed>>, failed: list<array<string, mixed>>, first_failure: ?DocumentDeliveryException}
     */
    protected function deliverToEach(array $recipients, callable $send): array
    {
        $sent = [];
        $failed = [];
        $firstFailure = null;

        foreach ($recipients as $person) {
            try {
                $delivery = $send($person);
            } catch (DocumentDeliveryException $e) {
                if (! $e->isAboutRecipient()) {
                    throw $e;
                }

                $firstFailure ??= $e;
                $failed[] = [
                    'person_id' => $person->getId(),
                    'person' => $person->getName(),
                    'reason' => $e->reason,
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            $sent[] = [
                'person_id' => $person->getId(),
                'person' => $person->getName(),
                ...$delivery->toToolResult(),
            ];
        }

        return ['recipients' => $sent, 'failed' => $failed, 'first_failure' => $firstFailure];
    }

    /**
     * The refusal when nobody got the document: the first contact's reason speaks for the send.
     *
     * @param array{recipients: list<array<string, mixed>>, failed: list<array<string, mixed>>, first_failure: ?DocumentDeliveryException} $result
     * @return array<string, mixed>
     */
    protected function undeliveredResult(array $result): array
    {
        return [
            'sent' => false,
            'reason' => $result['first_failure']?->reason,
            'message' => $result['first_failure']?->getMessage(),
            'failed' => $result['failed'],
        ];
    }
}
