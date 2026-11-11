<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Leads\Services;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

/**
 * A short, channel-agnostic log of AI contacts (voice call, SMS, ...) so whichever agent
 * reaches the person next knows when and how we last talked and what came of it.
 *
 * Kept in its own custom field, never in LEAD_CONTEXT_INFO: that is the BDC pipeline's array
 * context, merged into the SMS agent's session state.
 *
 * Stored on the lead when there is one, otherwise on the People (a contact that hasn't been
 * promoted yet); reads merge both, so history survives the promotion.
 */
class ContactHistoryService
{
    public const int MAX_ENTRIES = 10;

    public function record(
        Lead|People $entity,
        string $channel,
        ?string $direction,
        string $summary,
    ): void {
        $summary = Str::trimToNull($summary);
        if ($summary === null) {
            return;
        }

        $entries = $this->entries($entity);
        array_unshift($entries, [
            'channel' => $channel,
            'direction' => $direction,
            'summary' => $summary,
            'at' => now()->toIso8601String(),
        ]);

        $entity->set(
            ConfigurationEnum::LEAD_CONTACT_HISTORY->value,
            array_slice($entries, 0, self::MAX_ENTRIES)
        );
    }

    /**
     * Newest first, across the lead and its person.
     *
     * @return array<int, array{channel: string, direction: ?string, summary: string, at: string}>
     */
    public function forLead(Lead $lead, int $limit = 5): array
    {
        $entries = $this->entries($lead);
        if ($lead->people !== null) {
            $entries = array_merge($entries, $this->entries($lead->people));
        }

        return $this->newest($entries, $limit);
    }

    /**
     * @return array<int, array{channel: string, direction: ?string, summary: string, at: string}>
     */
    public function forPeople(People $people, int $limit = 5): array
    {
        return $this->newest($this->entries($people), $limit);
    }

    /**
     * One line per contact for an LLM prompt. A summary that already carries its own
     * "[voice outbound ...]" header (the voice runtime adds one) is used as-is.
     *
     * @param array<int, array{channel: string, direction: ?string, summary: string, at: string}> $entries
     */
    public function toPromptLines(array $entries): string
    {
        return implode("\n", array_map(
            fn (array $entry): string => str_starts_with($entry['summary'], '[')
                ? '- ' . $entry['summary']
                : sprintf(
                    '- [%s %s, %s] %s',
                    $entry['channel'],
                    $entry['direction'] ?? '',
                    $entry['at'],
                    $entry['summary']
                ),
            $entries
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entries(Lead|People $entity): array
    {
        $value = $entity->get(ConfigurationEnum::LEAD_CONTACT_HISTORY->value);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<int, array<string, mixed>>
     */
    private function newest(array $entries, int $limit): array
    {
        usort($entries, fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return array_slice($entries, 0, $limit);
    }
}
