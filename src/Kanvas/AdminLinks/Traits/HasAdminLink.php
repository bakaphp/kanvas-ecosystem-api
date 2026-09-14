<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\Traits;

use Kanvas\AdminLinks\DataTransferObject\AdminLinkMeta;
use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Services\AdminLinkService;

/**
 * Gives a model a deep link into Kanvas Admin. The model's only obligation is to
 * name its section; identifier resolution and URL assembly live behind this.
 *
 * The app comes from the model's own relation rather than a passed-in one, so a
 * link can never point at a different tenant than the record it describes.
 */
trait HasAdminLink
{
    abstract public function adminLinkSection(): AdminLinkSectionEnum;

    public function adminUrl(array $query = []): ?string
    {
        return $this->adminLinkMeta($query)->url;
    }

    public function adminLinkMeta(array $query = []): AdminLinkMeta
    {
        $section = $this->adminLinkSection();
        $identifier = $this->adminLinkIdentifier();

        if ($this->app === null || $identifier === null) {
            return new AdminLinkMeta(
                url: null,
                requiresCompany: $section->requiresCompany(),
                sectionPermission: $section->sectionPermission(),
            );
        }

        return new AdminLinkService()->meta(
            $this->app,
            $section,
            $identifier,
            $query
        );
    }

    public function adminLinkIdentifier(): string|int|null
    {
        return match ($this->adminLinkSection()->identifier()) {
            AdminLinkIdentifierEnum::UUID,
            AdminLinkIdentifierEnum::EITHER => $this->adminLinkRawAttribute('uuid'),
            AdminLinkIdentifierEnum::SLUG => $this->adminLinkRawAttribute('slug'),
            AdminLinkIdentifierEnum::ID => $this->getId(),
        };
    }

    /**
     * Read straight off the attribute bag — `$this->uuid` on a model without the
     * column would fall through Eloquent's __get into relation resolution.
     */
    private function adminLinkRawAttribute(string $key): ?string
    {
        $value = $this->getAttributes()[$key] ?? null;

        return $value !== null ? (string) $value : null;
    }
}
