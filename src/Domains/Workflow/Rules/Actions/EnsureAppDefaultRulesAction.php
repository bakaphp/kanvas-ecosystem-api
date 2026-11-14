<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * What every company of an app does on its own (answer an inbound message, reach out to a new lead,
 * apply a lead's AI mode, keep agent documents indexed) runs as a workflow rule, so each run is in
 * the integration history. One rule per app and behaviour, for every company (`companies_id = 0`):
 * a rule is a trigger only, and whether anything happens is decided in the activity (the agent's
 * switch, who owns the number). The list is `workflows.app_default_rules`. A condition is only a cheap
 * filter on plain values the event carries, so an event that concerns nobody starts no run at all; all
 * of an entry's conditions must hold.
 *
 * A rule per app, not one for the platform: a rule names the app's own system module.
 */
final class EnsureAppDefaultRulesAction
{
    public const string PARAM = 'app_default';

    public function __construct(
        private readonly Apps $app
    ) {
    }

    /**
     * @return list<Rule>
     */
    public function execute(): array
    {
        $rules = [];

        foreach ((array) config('workflows.app_default_rules', []) as $default) {
            $conditions = (array) ($default['conditions'] ?? []);

            $rule = new WireRuleAction(
                app: $this->app,
                companyId: 0,
                entityClass: (string) $default['model'],
                ruleTypeName: (string) $default['trigger'],
                activityClass: (string) $default['activity'],
                name: (string) $default['name'],
                description: 'Runs for every company of the app; the activity decides whether anything happens.',
                conditions: $conditions,
                params: [self::PARAM => true],
                isAsync: (bool) ($default['async'] ?? true),
                pattern: $conditions !== [] ? implode(' and ', range(1, count($conditions))) : null,
            )->execute();

            if ($rule === null) {
                Log::warning('App default rule skipped: the activity is not in the actions catalog yet', [
                    'apps_id' => $this->app->getId(),
                    'activity' => $default['activity'],
                ]);

                continue;
            }

            $rules[] = $rule;
        }

        return $rules;
    }
}
