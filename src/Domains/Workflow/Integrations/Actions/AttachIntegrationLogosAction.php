<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\Integrations;

/**
 * Pulls each integration's logo from devicons.io (falling back to Simple Icons) into our own storage,
 * so the UI never hotlinks a third-party host. Logos are global: they are read back from any app's
 * system module (FilesystemQuery@getFileByGraphTypeFromAnyApp), so one run covers every app.
 */
class AttachIntegrationLogosAction
{
    public const string DEVICONS_URL = 'https://devicons.io/devicons/icons';
    public const string SIMPLE_ICONS_URL = 'https://cdn.simpleicons.org';
    public const string FIELD_NAME = 'logo';

    /**
     * Integration names that don't normalize to their devicon slug.
     */
    private const array ALIASES = [
        'claude-agent' => 'claude',
        'teams' => 'microsoft-teams',
        'microsoft365' => 'microsoft',
        'ms-learn' => 'microsoft',
        'meta-ads' => 'meta',
        'pipeboard-meta-ads' => 'meta',
        'pipeboard-google-ads' => 'google-ads',
        'google-analytics-admin' => 'google-analytics',
        'tiktok-ads' => 'tiktok',
        'whatsapp-business' => 'whatsapp',
        'slack-notifications' => 'slack',
        'sap-btp' => 'sap',
    ];

    /**
     * @var array<string, string|null> slug => icon url
     */
    private array $resolvedIcons = [];

    /**
     * @var array<string, Filesystem> icon url => uploaded file, so stripe and stripe_mcp share one upload
     */
    private array $uploadedIcons = [];

    /**
     * @param Apps                  $app           whose storage the logos are uploaded to
     * @param array<string, string> $logoOverrides integration name => logo url, for brands no icon set carries;
     *                                             these always replace an existing logo
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Users $user,
        private readonly bool $onlyAppIntegrations = false,
        private readonly bool $overwrite = false,
        private readonly array $logoOverrides = [],
        private readonly string $deviconsUrl = self::DEVICONS_URL,
        private readonly string $simpleIconsUrl = self::SIMPLE_ICONS_URL,
    ) {
    }

    /**
     * @return array{attached: array<string, string>, skipped: list<string>, missing: list<string>}
     */
    public function execute(): array
    {
        $result = ['attached' => [], 'skipped' => [], 'missing' => []];
        $filesystem = new FilesystemServices($this->app);

        $integrations = Integrations::query()
            ->when($this->onlyAppIntegrations, fn ($query) => $query->fromPublicOrCurrentApp($this->app))
            ->notDeleted()
            ->orderBy('id')
            ->get();

        foreach ($integrations as $integration) {
            $label = $integration->name . ' #' . $integration->getId();

            $override = $this->logoOverrides[$integration->name] ?? null;
            $keepExistingLogo = $override === null && ! $this->overwrite;

            if ($keepExistingLogo && FilesystemEntitiesRepository::getFileFromEntityByNameFromAnyApp($integration, self::FIELD_NAME) !== null) {
                $result['skipped'][] = $label;

                continue;
            }

            $iconUrl = $override ?? $this->resolveIconUrl($integration->name);

            if ($iconUrl === null) {
                $result['missing'][] = $label;

                continue;
            }

            $this->uploadedIcons[$iconUrl] ??= $filesystem->uploadFileFromUrl($iconUrl, $this->user);
            $integration->addFile($this->uploadedIcons[$iconUrl], self::FIELD_NAME);

            $result['attached'][$label] = $iconUrl;
        }

        return $result;
    }

    /**
     * Prefers devicons' square "-icon" mark over its wordmark, since logos render in small tiles.
     * Simple Icons is the fallback: wider brand coverage, but single-color marks and dash-less slugs.
     */
    private function resolveIconUrl(string $integrationName): ?string
    {
        $slug = strtolower(str_replace('_', '-', preg_replace('/_mcp$/', '', $integrationName)));
        $slug = self::ALIASES[$slug] ?? $slug;

        if (! array_key_exists($slug, $this->resolvedIcons)) {
            $this->resolvedIcons[$slug] = null;

            $candidates = [
                $this->deviconsUrl . '/' . $slug . '-icon.svg',
                $this->deviconsUrl . '/' . $slug . '.svg',
                $this->simpleIconsUrl . '/' . str_replace('-', '', $slug),
            ];

            foreach ($candidates as $url) {
                if (Http::timeout(10)->head($url)->successful()) {
                    $this->resolvedIcons[$slug] = $url;

                    break;
                }
            }
        }

        return $this->resolvedIcons[$slug];
    }
}
