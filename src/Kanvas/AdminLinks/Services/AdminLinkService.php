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
        $host = $this->setting($app, AppSettingsEnums::ADMIN_URL) ?: config('kanvas.app.frontend_url');

        if (empty($host)) {
            return null;
        }

        return rtrim((string) $host, '/');
    }

    /**
     * Where a console-sent admin invite lands. Null means there is no console to point at (a frontend
     * such as localhost has no app. prefix to rewrite), so callers keep the app frontend.
     */
    public function consoleHost(Apps $app): ?string
    {
        $configured = $this->setting($app, AppSettingsEnums::CONSOLE_URL) ?: config('kanvas.app.console_url');

        if (! empty($configured)) {
            return rtrim((string) $configured, '/');
        }

        $frontend = (string) config('kanvas.app.frontend_url');
        $derived = preg_replace('#^(https?://)app\.#i', '$1console.', $frontend, 1, $replaced);

        return $replaced === 1 ? rtrim($derived, '/') : null;
    }

    /**
     * Settings keys are free-form strings and the lookup is case-sensitive: an app that stored the key
     * in upper case reads back as unconfigured unless both spellings are tried.
     */
    private function setting(Apps $app, AppSettingsEnums $key): mixed
    {
        $name = $key->getValue();

        return $app->get($name) ?: $app->get(strtoupper($name));
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
