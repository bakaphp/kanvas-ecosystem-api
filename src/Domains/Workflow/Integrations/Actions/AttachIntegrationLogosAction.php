<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\Integrations;

/**
 * Pulls each integration's logo from open icon sets (falling back to the vendor's favicon) into our
 * own storage, so the UI never hotlinks a third-party host. Logos are global: they are read back from
 * any app's system module (FilesystemQuery@getFileByGraphTypeFromAnyApp), so one run covers every app.
 */
class AttachIntegrationLogosAction
{
    public const string FIELD_NAME = 'logo';

    /**
     * Tried in order. Colored sets first; Simple Icons is single-color. Serve everything from
     * devicons.io or jsDelivr: cdn.simpleicons.org sits behind a Cloudflare bot check that
     * rejects server IPs while answering a laptop fine.
     */
    public const array SOURCES = [
        'devicons' => 'https://devicons.io/devicons/icons',
        'dashboard_icons' => 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/svg',
        'simple_icons' => 'https://cdn.jsdelivr.net/npm/simple-icons@16/icons',
        'favicons' => 'https://www.google.com/s2/favicons',
    ];

    /**
     * Integration names that don't normalize to their icon slug.
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
        'google-people' => 'google-contacts',
        'tiktok-ads' => 'tiktok',
        'whatsapp-business' => 'whatsapp',
        'slack-notifications' => 'slack',
        'sap-btp' => 'sap',
        'mssql' => 'microsoft-sql-server',
    ];

    /**
     * Vendors no icon set carries, by slug. Only domains whose favicon comes back sharp belong here;
     * the size check in faviconUrl() still guards against one going blurry later.
     */
    private const array WEBSITES = [
        '700-credit' => '700credit.com',
        'browserbase' => 'browserbase.com',
        'cardnet' => 'cardnet.com.do',
        'chromedata' => 'chromedata.com',
        'dealersocket' => 'dealersocket.com',
        'deel' => 'deel.com',
        'drive-centric' => 'drivecentric.com',
        'intellicheck' => 'intellicheck.com',
        'jina' => 'jina.ai',
        'klaviyo' => 'klaviyo.com',
        'mercury' => 'mercury.com',
        'mindee' => 'mindee.com',
        'recombee' => 'recombee.com',
        'respond-io' => 'respond.io',
        'supadata' => 'supadata.ai',
        'tavily' => 'tavily.com',
        'universal-assistance' => 'universal-assistance.com',
        'vinsolution' => 'vinsolutions.com',
        'wa-sender' => 'wasenderapi.com',
    ];

    /**
     * Google answers an unknown domain with a 16px globe, never a 404.
     */
    private const int MIN_FAVICON_SIZE = 128;

    /**
     * @var array<string, string|null> slug => icon url
     */
    private array $resolvedIcons = [];

    /**
     * @var array<string, Filesystem> icon url => uploaded file, so stripe and stripe_mcp share one upload
     */
    private array $uploadedIcons = [];

    /**
     * @var array<string, string> url => why the source failed, when it was anything but "no such icon"
     */
    private array $sourceErrors = [];

    /**
     * @param Apps                  $app           whose storage the logos are uploaded to
     * @param array<string, string> $logoOverrides integration name => logo url, for brands no icon set carries;
     *                                             these always replace an existing logo
     * @param array<string, string> $sourceUrls    same keys as SOURCES
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Users $user,
        private readonly bool $onlyAppIntegrations = false,
        private readonly bool $overwrite = false,
        private readonly array $logoOverrides = [],
        private readonly array $sourceUrls = self::SOURCES,
    ) {
    }

    /**
     * @return array{
     *     attached: array<string, string>,
     *     skipped: list<string>,
     *     missing: list<string>,
     *     source_errors: array<string, string>
     * }
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

        $result['source_errors'] = $this->sourceErrors;

        return $result;
    }

    /**
     * Prefers devicons' square "-icon" mark over its wordmark, since logos render in small tiles.
     */
    private function resolveIconUrl(string $integrationName): ?string
    {
        $slug = strtolower(str_replace('_', '-', preg_replace('/_mcp$/', '', $integrationName)));
        $slug = self::ALIASES[$slug] ?? $slug;

        if (array_key_exists($slug, $this->resolvedIcons)) {
            return $this->resolvedIcons[$slug];
        }

        $candidates = [
            $this->sourceUrls['devicons'] . '/' . $slug . '-icon.svg',
            $this->sourceUrls['devicons'] . '/' . $slug . '.svg',
            $this->sourceUrls['dashboard_icons'] . '/' . $slug . '.svg',
            $this->sourceUrls['simple_icons'] . '/' . str_replace('-', '', $slug) . '.svg',
        ];

        foreach ($candidates as $url) {
            if ($this->exists($url)) {
                return $this->resolvedIcons[$slug] = $url;
            }
        }

        return $this->resolvedIcons[$slug] = $this->faviconUrl($slug);
    }

    private function exists(string $url): bool
    {
        $response = $this->request('HEAD', $url);

        if ($response === null) {
            return false;
        }

        if (! $response->successful() && $response->status() !== 404) {
            $this->sourceErrors[$url] = 'HTTP ' . $response->status();
        }

        return $response->successful();
    }

    private function faviconUrl(string $slug): ?string
    {
        $website = self::WEBSITES[$slug] ?? null;

        if ($website === null) {
            return null;
        }

        $url = $this->sourceUrls['favicons'] . '?domain=' . $website . '&sz=256';

        $response = $this->request('GET', $url);

        if ($response === null) {
            return null;
        }

        $size = $response->successful() ? @getimagesizefromstring($response->body()) : false;

        if ($size === false || $size[0] < self::MIN_FAVICON_SIZE) {
            $this->sourceErrors[$url] = $size === false
                ? 'HTTP ' . $response->status() . ', not an image'
                : 'favicon is only ' . $size[0] . 'px';

            return null;
        }

        return $url;
    }

    private function request(string $method, string $url): ?Response
    {
        try {
            return Http::timeout(10)->send($method, $url);
        } catch (ConnectionException $e) {
            $this->sourceErrors[$url] = $e->getMessage();

            return null;
        }
    }
}
