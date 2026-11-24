<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\Services;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Exceptions\ValidationException;

/**
 * The researched profile lives in a company setting. The Guild organization tools use the same key on
 * Organization rows (the tenant's customers); that is a different entity and no tool reads this one.
 */
final class CompanyProfileService
{
    public const string SETTING_KEY = 'company_profile';

    private const int CACHE_SECONDS = 600;
    private const int SUMMARY_CHARS = 1_200;
    private const array FILLABLE_COLUMNS = ['website', 'phone', 'address'];

    /**
     * @param array<string, mixed> $profile
     */
    public function write(Companies $company, array $profile): void
    {
        $profile = self::clean($profile) + [
            'source' => 'website',
            'researched_at' => Carbon::now()->toIso8601String(),
        ];
        $company->set(self::SETTING_KEY, $profile);

        foreach (self::FILLABLE_COLUMNS as $column) {
            $value = is_scalar($profile[$column] ?? null) ? Str::trimToNull((string) $profile[$column]) : null;
            if ($value === null || Str::trimToNull((string) $company->{$column}) !== null) {
                continue;
            }
            if ($column === 'website') {
                try {
                    $value = WebsiteUrl::normalize($value);
                } catch (ValidationException) {
                    continue;
                }
            }
            $company->{$column} = $value;
        }

        if ($company->isDirty()) {
            $company->saveOrFail();
        }

        Cache::forget(self::cacheKey($company));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(Companies $company): ?array
    {
        $profile = $company->get(self::SETTING_KEY);

        return is_array($profile) && $profile !== [] ? $profile : null;
    }

    /**
     * The block a system agent's instructions carry. Cached because every turn of every internal
     * agent would otherwise read the settings store; write() drops it.
     */
    public static function promptBlock(?CompanyInterface $company): string
    {
        if (! $company instanceof Companies) {
            return '';
        }

        return (string) Cache::remember(
            self::cacheKey($company),
            self::CACHE_SECONDS,
            static fn (): string => self::render(new self()->read($company)),
        );
    }

    /**
     * @param array<string, mixed>|null $profile
     */
    private static function render(?array $profile): string
    {
        if ($profile === null) {
            return '';
        }

        $lines = ['## Company profile'];
        foreach ([
            'name' => 'Name',
            'industry' => 'Industry',
            'offering' => 'Offering',
            'sells_to' => 'Sells to',
            'locations' => 'Locations',
            'languages' => 'Languages',
            'tone' => 'Tone',
        ] as $key => $label) {
            $value = $profile[$key] ?? null;
            $text = is_array($value) ? implode(', ', array_filter(array_map('strval', $value))) : Str::trimToNull((string) $value);
            if ($text !== null && $text !== '') {
                $lines[] = $label . ': ' . $text;
            }
        }

        $summary = Str::trimToNull((string) ($profile['summary'] ?? ''));
        if ($summary !== null) {
            $lines[] = '';
            $lines[] = mb_substr($summary, 0, self::SUMMARY_CHARS);
        }

        return count($lines) === 1 ? '' : implode("\n", $lines) . "\n\n";
    }

    /**
     * The model answers an unknown field with an empty string or list; stored as null so readers can
     * use `??` without a second emptiness check.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function clean(array $profile): array
    {
        foreach ($profile as $key => $value) {
            $profile[$key] = match (true) {
                is_string($value) => Str::trimToNull($value),
                is_array($value) => $value === [] ? null : (array_is_list($value) ? $value : self::clean($value)),
                default => $value,
            };
        }

        return $profile;
    }

    private static function cacheKey(Companies $company): string
    {
        return 'company_profile_block:' . $company->getId();
    }
}
