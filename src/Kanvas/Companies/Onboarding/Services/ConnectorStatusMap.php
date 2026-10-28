<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Services\SlackListenerStatusService;
use Kanvas\Exceptions\ValidationException;

/**
 * Connected-or-not for the requirement options keyed by `connector` instead of `integration_id`:
 * connectors with no `integrations` row, whose state lives somewhere of their own. Adding a
 * connector means adding one entry here.
 */
final class ConnectorStatusMap
{
    public const string SLACK_WORKSPACE = 'slack_workspace';

    /**
     * @var array<string, true> unknown connectors already reported this process, so a bad catalog row costs one Sentry event
     */
    private static array $reported = [];

    public static function connected(Apps $app, Companies $company, string $connector): bool
    {
        return match ($connector) {
            self::SLACK_WORKSPACE => (bool) (new SlackListenerStatusService()->forCompany($app, $company)['connected'] ?? false),
            default => self::unknown($connector),
        };
    }

    private static function unknown(string $connector): bool
    {
        if (! isset(self::$reported[$connector])) {
            self::$reported[$connector] = true;
            report(new ValidationException("Onboarding option names an unknown connector \"{$connector}\""));
        }

        return false;
    }
}
