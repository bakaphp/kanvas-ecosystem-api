<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Services;

use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Classification\Agents\PeopleClassificationAgent;
use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Models\People;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

/**
 * Most people of a tenant share a handful of titles, so a result is cached per app and title. The
 * taxonomy hash in the key is what makes a taxonomy change reclassify.
 */
class PeopleTitleClassifierService
{
    private const int CACHE_TTL_SECONDS = 2592000;

    public static function titleOf(People $people): ?string
    {
        return Str::trimToNull((string) $people->get('position'))
            ?? Str::trimToNull((string) $people->get('title'))
            ?? Str::trimToNull((string) $people->employmentHistory()
                ->where('status', 1)
                ->orderByDesc('id')
                ->value('position'));
    }

    public static function fingerprint(string $title, PeopleTaxonomy $taxonomy): string
    {
        return sha1(self::normalize($title) . '|' . $taxonomy->hash());
    }

    /**
     * @return array{level: ?string, area: ?string, confidence: float, normalized_title: string}
     */
    public static function classify(
        Apps $app,
        Companies $company,
        PeopleTaxonomy $taxonomy,
        string $title,
        bool $fresh = false
    ): array {
        $key = 'people-classification:' . $app->getId() . ':' . self::fingerprint($title, $taxonomy);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn (): array => self::callModel(
            $app,
            $company,
            $taxonomy,
            $title,
        ));
    }

    /**
     * @return array{level: ?string, area: ?string, confidence: float, normalized_title: string}
     */
    private static function callModel(
        Apps $app,
        Companies $company,
        PeopleTaxonomy $taxonomy,
        string $title
    ): array {
        $agent = new PeopleClassificationAgent();
        $agent->setConfigurationWithoutAgent($app, $company);
        $agent->setTaxonomy($taxonomy);

        $response = $agent->promptWithConfig('job_title: ' . $title);
        $data = $response instanceof StructuredAgentResponse ? $response->structured : [];

        // Thrown rather than read as confidence 0: that would be cached and hashed as the title's real
        // answer, leaving everyone with this title in review until someone forces a rerun.
        if (! is_numeric($data['confidence'] ?? null)) {
            throw new RuntimeException('The classification model returned no usable answer.');
        }

        $level = is_string($data['level'] ?? null) ? $data['level'] : null;
        $area = is_string($data['area'] ?? null) ? $data['area'] : null;

        return [
            'level' => $taxonomy->hasLevel($level) ? $level : null,
            'area' => $taxonomy->hasArea($area) ? $area : null,
            'confidence' => max(0.0, min(1.0, (float) $data['confidence'])),
            'normalized_title' => Str::trimmedStringOrNull($data['normalized_title'] ?? null) ?? $title,
        ];
    }

    private static function normalize(string $title): string
    {
        return (string) preg_replace('/\s+/u', ' ', Str::lowerTrim($title));
    }
}
