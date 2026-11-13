<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Services;

use Baka\Support\Str;
use Illuminate\Support\Facades\File;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Templates\Models\Templates;
use Kanvas\Templates\Repositories\TemplatesRepository;

/**
 * A company's own SOW layouts: named versions stored as company-level `scribe-sow-{version}` template rows, and
 * a company setting pointing at the default one. The default is a pointer so switching never overwrites a
 * layout; no pointer means the app's stock `scribe-sow`. Nothing here writes an app or platform row.
 */
final class CompanySowTemplateService
{
    public const string DEFAULT_SETTING = 'scribe_sow_default_template';
    public const string VERSION_PREFIX = 'scribe-sow-';
    private const int MAX_VERSION_LENGTH = 40;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    public static function templateName(?string $version): string
    {
        return $version === null ? QuoteSowPdfService::TEMPLATE_NAME : self::VERSION_PREFIX . $version;
    }

    public static function normalizeVersion(string $version): string
    {
        $slug = Str::slug($version);

        if ($slug === '' || strlen($slug) > self::MAX_VERSION_LENGTH) {
            throw new ValidationException(
                'A SOW template version is a short name of letters, digits and dashes (e.g. "retainer"), at most '
                . self::MAX_VERSION_LENGTH . ' characters.'
            );
        }

        return $slug;
    }

    public function find(string $version): ?Templates
    {
        /** @var Templates|null $template */
        $template = Templates::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('name', self::templateName($version))
            ->notDeleted()
            ->first();

        return $template;
    }

    /**
     * @return list<Templates>
     */
    public function versions(): array
    {
        return Templates::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('name', 'like', self::VERSION_PREFIX . '%')
            ->notDeleted()
            ->orderBy('name')
            ->get()
            ->all();
    }

    public static function versionOf(Templates $template): string
    {
        return substr((string) $template->name, strlen(self::VERSION_PREFIX));
    }

    /**
     * A pointer at a version that no longer exists reads as no default, so a new SOW falls back to stock instead
     * of failing.
     */
    public function defaultVersion(): ?string
    {
        $version = Str::trimToNull($this->company->get(self::DEFAULT_SETTING));

        return $version !== null && $this->find($version) !== null ? $version : null;
    }

    public function setDefault(?string $version): void
    {
        if ($version === null) {
            $this->company->del(self::DEFAULT_SETTING);

            return;
        }

        $this->requireVersion($version);
        $this->company->set(self::DEFAULT_SETTING, $version);
    }

    public function requireVersion(string $version): Templates
    {
        $template = $this->find($version);

        if ($template !== null) {
            return $template;
        }

        $known = array_map(self::versionOf(...), $this->versions());

        throw new ValidationException(
            "This company has no SOW template version \"{$version}\". "
            . ($known === [] ? 'It has none yet; save one first.' : 'Versions: ' . implode(', ', $known) . '.')
        );
    }

    /**
     * The Twig a SOW written now would use for $version (or the default): the company version, else the app's
     * stock row, else the packaged file. What the agent edits always starts from what is in effect.
     */
    public function source(?string $version = null): string
    {
        $version ??= $this->defaultVersion();

        if ($version !== null) {
            return (string) $this->requireVersion($version)->template;
        }

        try {
            return (string) TemplatesRepository::getByName(QuoteSowPdfService::TEMPLATE_NAME, $this->app, $this->company)->template;
        } catch (ModelNotFoundException) {
            return File::get(resource_path(QuoteSowPdfService::PACKAGED_TEMPLATE));
        }
    }
}
