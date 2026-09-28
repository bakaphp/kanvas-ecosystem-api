<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\Services;

use Kanvas\AdminLinks\DataTransferObject\AdminLinkMeta;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ValidationException;

/**
 * The only place that knows what a Kanvas Admin URL looks like.
 */
class AdminLinkService
{
    public function build(
        Apps $app,
        AdminLinkSectionEnum $section,
        string|int|null $identifier = null,
        array $query = []
    ): ?string {
        $host = $this->resolveHost($app);

        if ($host === null) {
            return null;
        }

        // A console deployment redirects every platform route to Control Center
        // and drops the query string, so emitting one is a guaranteed dead link.
        if ($this->isConsoleHost($host) && ! $section->isControlCenter()) {
            return null;
        }

        if ($identifier !== null) {
            $this->assertIdentifierShape($section, (string) $identifier);
        }

        $parts = [
            'projects',
            rawurlencode($app->key),
            $identifier !== null ? $section->segment() : $section->listSegment(),
        ];

        if ($identifier !== null) {
            $parts[] = rawurlencode((string) $identifier);
        }

        $path = implode('/', $parts);

        return $host . '/' . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    public function meta(
        Apps $app,
        AdminLinkSectionEnum $section,
        string|int|null $identifier = null,
        array $query = []
    ): AdminLinkMeta {
        return new AdminLinkMeta(
            url: $this->build(
                $app,
                $section,
                $identifier,
                $query
            ),
            requiresCompany: $section->requiresCompany(),
            sectionPermission: $section->sectionPermission(),
        );
    }

    public function resolveHost(Apps $app): ?string
    {
        $key = AppSettingsEnums::ADMIN_URL->getValue();

        // Settings keys are free-form strings and the lookup is case-sensitive, so an app configured
        // with ADMIN_URL reads back as "not configured" and the agent tells the user the platform
        // cannot build links — with the value sitting right there in apps_settings.
        $host = $app->get($key)
            ?: $app->get(strtoupper($key))
            ?: config('kanvas.app.frontend_url');

        if (empty($host)) {
            return null;
        }

        return rtrim((string) $host, '/');
    }

    private function isConsoleHost(string $host): bool
    {
        $parsed = parse_url($host, PHP_URL_HOST);
        $hostname = is_string($parsed) ? $parsed : $host;

        return str_starts_with($hostname, 'console.');
    }

    private function assertIdentifierShape(AdminLinkSectionEnum $section, string $identifier): void
    {
        if ($section->identifier()->matches($identifier)) {
            return;
        }

        throw new ValidationException(
            'Identifier "' . $identifier . '" is not valid for the ' . $section->alias()
            . ' admin route, which expects ' . $section->identifier()->describe() . '.'
        );
    }
}
