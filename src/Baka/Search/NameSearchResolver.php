<?php

declare(strict_types=1);

namespace Baka\Search;

use Baka\Search\Contracts\NameSearchInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Engines\TypesenseEngine;
use Throwable;

/**
 * Picks the engine-backed name search for an app, mirroring SearchEngineResolver so bulk matching and
 * the app's normal search can never disagree about which engine the tenant is on.
 *
 * Returns null when the app has no usable engine — the caller decides what that means (today: fall
 * back to a database scan). Every failure is reported rather than swallowed, so an unconfigured or
 * unreachable engine shows up in Sentry instead of quietly becoming the steady state.
 */
class NameSearchResolver
{
    /**
     * Per-app opt-in, off by default. An engine only answers correctly once the app's records are
     * actually indexed AND the engine is configured to filter on apps_id/companies_id — neither is
     * true just because a driver is set. Meilisearch, for one, rejects a filter on an attribute
     * missing from its index-settings, and an unpopulated index answers "no matches" rather than
     * failing, which is the worse outcome.
     *
     * Turn on per app once its reindex is verified:
     *   $app->set(NameSearchResolver::ENABLED_SETTING, true);
     */
    public const string ENABLED_SETTING = 'bulk_name_search_engine';

    public function for(Apps $app, Model $model): ?NameSearchInterface
    {
        if (! $app->get(self::ENABLED_SETTING)) {
            return null;
        }

        $model->setRelation('app', $app);

        try {
            $engine = app(SearchEngineResolver::class)->resolveEngine($model, $app);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($engine instanceof NullEngine) {
            return null;
        }

        if ($engine instanceof TypesenseEngine) {
            try {
                return new TypesenseNameSearch(
                    SearchEngineResolver::getTypesenseClient($app->get('typesense_search_settings') ?? []),
                );
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        }

        return new EngineNameSearch($engine);
    }
}
