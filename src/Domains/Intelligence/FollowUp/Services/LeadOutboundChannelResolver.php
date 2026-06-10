<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ResolvedChannel;

/**
 * Maps a Lead's reachable contacts against a stage's enabled channels.
 * Opt-outs (`is_opt_out = 1`) are filtered out. See FollowUp CLAUDE.md
 * "Channel resolution lives on the Lead" + v1.5 TODOs.
 */
final class LeadOutboundChannelResolver
{
    private const array EMAIL_CONTACT_TYPES = [
        ContactTypeEnum::PRIMARY_EMAIL,
        ContactTypeEnum::EMAIL,
        ContactTypeEnum::SECONDARY_EMAIL,
    ];

    private const array SMS_CONTACT_TYPES = [
        ContactTypeEnum::CELLPHONE,
        ContactTypeEnum::PHONE,
        ContactTypeEnum::WORK_PHONE,
    ];

    /**
     * @return ResolvedChannel[]  in stage-config order; opt-outs excluded
     */
    public function resolve(Lead $lead, FollowUpConfig $config): array
    {
        $people = $lead->people;
        if ($people === null) {
            return [];
        }

        $contacts = $people->contacts()
            ->where('is_opt_out', 0)
            ->get();

        $resolved = [];

        foreach ($config->channels as $channelConfig) {
            if (! $channelConfig->enabled) {
                continue;
            }

            $contact = $this->pickContactForChannel($channelConfig->type, $contacts);
            if ($contact === null) {
                continue;
            }

            $resolved[] = new ResolvedChannel(
                channelType: $channelConfig->type,
                contact: $contact,
                reason: $this->reasonFor($channelConfig->type, $contact),
            );
        }

        return $resolved;
    }

    private function pickContactForChannel(string $channelType, Collection $contacts): ?Contact
    {
        $eligibleTypeIds = match ($channelType) {
            'email' => array_map(fn (ContactTypeEnum $t) => $t->value, self::EMAIL_CONTACT_TYPES),
            'sms' => array_map(fn (ContactTypeEnum $t) => $t->value, self::SMS_CONTACT_TYPES),
            'whatsapp' => [ContactTypeEnum::CELLPHONE->value],
            default => [],
        };

        if ($eligibleTypeIds === []) {
            return null;
        }

        $filtered = $contacts->filter(
            fn (Contact $c) => in_array((int) $c->contacts_types_id, $eligibleTypeIds, true)
        );

        if ($filtered->isEmpty()) {
            return null;
        }

        $order = array_flip($eligibleTypeIds);

        return $filtered
            ->sortBy([
                fn (Contact $a, Contact $b) => ($order[(int) $a->contacts_types_id] ?? PHP_INT_MAX) <=> ($order[(int) $b->contacts_types_id] ?? PHP_INT_MAX),
                fn (Contact $a, Contact $b) => (int) $b->weight <=> (int) $a->weight,
                fn (Contact $a, Contact $b) => (int) $b->getKey() <=> (int) $a->getKey(),
            ])
            ->first();
    }

    private function reasonFor(string $channelType, Contact $contact): string
    {
        $typeId = (int) $contact->contacts_types_id;

        return match ($channelType) {
            'email' => match ($typeId) {
                ContactTypeEnum::PRIMARY_EMAIL->value => 'primary_email',
                ContactTypeEnum::SECONDARY_EMAIL->value => 'secondary_email',
                default => 'email',
            },
            'sms' => match ($typeId) {
                ContactTypeEnum::CELLPHONE->value => 'cellphone_for_sms',
                ContactTypeEnum::PHONE->value => 'home_phone_for_sms',
                ContactTypeEnum::WORK_PHONE->value => 'work_phone_for_sms',
                default => 'sms',
            },
            'whatsapp' => 'cellphone_for_whatsapp',
            default => $channelType,
        };
    }
}
