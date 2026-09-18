<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows\Integrations;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\Actions\AttachIntegrationLogosAction;

class AttachIntegrationLogosCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:integrations-attach-logos
        {app_id? : Limit to the integrations one app can see; omitted, every integration row gets a logo}
        {--overwrite : Replace logos that are already attached}
        {--logo=* : name=url for an integration no icon set covers, e.g. --logo=klaviyo_mcp=https://...}';

    protected $description = 'Attach devicons.io / Simple Icons logos to integrations, shared by every app';

    public function handle(): int
    {
        $appId = $this->argument('app_id');
        $app = $appId !== null ? Apps::getById((int) $appId) : app(Apps::class);
        $this->overwriteAppService($app);

        $result = new AttachIntegrationLogosAction(
            app: $app,
            user: $app->keys()->firstOrFail()->user,
            onlyAppIntegrations: $appId !== null,
            overwrite: (bool) $this->option('overwrite'),
            logoOverrides: $this->parseLogoOverrides(),
        )->execute();

        foreach ($result['attached'] as $integration => $iconUrl) {
            $this->line("  ✓ {$integration} ← {$iconUrl}");
        }

        foreach ($result['skipped'] as $integration) {
            $this->line("  · {$integration} already has a logo");
        }

        foreach ($result['missing'] as $integration) {
            $this->warn("  ✗ {$integration} has no icon on devicons.io or Simple Icons; pass --logo");
        }

        $this->info(sprintf(
            'Attached %d, skipped %d, missing %d',
            count($result['attached']),
            count($result['skipped']),
            count($result['missing'])
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function parseLogoOverrides(): array
    {
        $overrides = [];

        foreach ((array) $this->option('logo') as $pair) {
            [$name, $url] = array_pad(explode('=', (string) $pair, 2), 2, '');

            if ($name === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException("Invalid --logo \"{$pair}\", expected name=url");
            }

            $overrides[$name] = $url;
        }

        return $overrides;
    }
}
