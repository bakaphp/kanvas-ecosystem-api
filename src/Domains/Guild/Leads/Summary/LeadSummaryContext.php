<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Baka\Support\Arr;
use Baka\Support\Str;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Research\Services\CompanyProfileService;

/**
 * What the summary model is told about the business before it reads the messages: the company profile,
 * the facts this tenant tracks (`agent_lead_summary_keys`, company setting over app setting), and the
 * value the lead record already holds for each of those keys, so the summary agrees with the CRM
 * instead of guessing. Read only: the summary never writes a custom field.
 */
final class LeadSummaryContext
{
    public static function for(Lead $lead): string
    {
        $parts = array_filter([
            CompanyProfileService::promptBlock($lead->company),
            self::trackedFacts($lead),
        ]);

        return implode("\n\n", $parts);
    }

    /**
     * The configured keys, each as `key` or `key: description`. A setting may be a JSON list of strings or
     * of `{key, description}` objects, or a comma-separated string of keys.
     *
     * @return array<string, string> key => description ('' when none)
     */
    public static function keys(Lead $lead): array
    {
        $raw = $lead->company->get(AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS->value)
            ?? $lead->app->get(AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS->value);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : Arr::fromSettingList($raw);
        }

        $keys = [];

        foreach ((array) $raw as $entry) {
            $key = Str::trimToNull(is_array($entry) ? (string) ($entry['key'] ?? '') : (string) $entry);

            if ($key !== null) {
                $keys[Str::snake($key)] = trim((string) (is_array($entry) ? ($entry['description'] ?? '') : ''));
            }
        }

        return $keys;
    }

    private static function trackedFacts(Lead $lead): string
    {
        $keys = self::keys($lead);

        if ($keys === []) {
            return '';
        }

        $lines = ['## Facts this business tracks (report each as a key fact, under this exact key, whenever the conversation states it)'];

        foreach ($keys as $key => $description) {
            $lines[] = '- ' . $key . ($description === '' ? '' : ': ' . $description);
        }

        $known = [];

        foreach (array_keys($keys) as $key) {
            $value = $lead->get($key);

            if ($value !== null && $value !== '' && $value !== []) {
                $known[] = '- ' . $key . ': ' . (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        }

        if ($known !== []) {
            $lines[] = '';
            $lines[] = 'On the lead record now (the CRM value; keep it unless the conversation changes it):';
            array_push($lines, ...$known);
        }

        return implode("\n", $lines);
    }
}
