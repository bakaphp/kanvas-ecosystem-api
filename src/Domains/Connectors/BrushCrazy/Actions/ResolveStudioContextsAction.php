<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\StudioModeEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Exceptions\ValidationException;
use stdClass;

/**
 * Maps each BrushCrazy studio onto Kanvas tenancy and returns the contexts every other action
 * reads its company from.
 *
 * Production has 5 studios. Studio 0 ("Corporate") owns zero calendarables, but it is not a
 * placeholder: `PurchaseGiftCardController` opens every online e-gift-card sale with
 * `startSale($user, 0)`, so studio 0 is where all direct-to-consumer gift card revenue lands,
 * and `SalePolicy` exempts `studio_id === 0` from the per-studio visibility check because
 * that revenue belongs to head office, not to a franchise. It needs a real Company.
 */
class ResolveStudioContextsAction
{
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected ?Companies $fallbackCompany = null,
    ) {
    }

    /**
     * @return array<int, StudioContext> keyed by BrushCrazy studios.id
     */
    public function execute(): array
    {
        $mode = StudioModeEnum::tryFrom(
            (string) ($this->app->get(ConfigurationEnum::BRUSHCRAZY_STUDIO_MODE->value) ?? StudioModeEnum::COMPANY->value)
        ) ?? StudioModeEnum::COMPANY;

        if ($mode === StudioModeEnum::BRANCH) {
            // Deliberately unimplemented rather than guessed at: nobody selected this mode, and an
            // untested second tenancy path is worse than a clear failure. Branch mode also needs a
            // per-branch filter on every Event query, since the domain has no branches_id column.
            throw new ValidationException('BrushCrazy studio mode "branch" is not implemented; run with company mode.');
        }

        $defaultTimezone = (string) ($this->app->get(ConfigurationEnum::BRUSHCRAZY_DEFAULT_TIMEZONE->value) ?? 'America/Denver');
        $contexts = [];

        foreach (new Client($this->app)->table('studios')->orderBy('id')->get() as $studio) {
            $contexts[(int) $studio->id] = $this->resolveStudio($studio, $defaultTimezone);
        }

        return $contexts;
    }

    protected function resolveStudio(stdClass $studio, string $defaultTimezone): StudioContext
    {
        $bcStudioId = (int) $studio->id;
        $name = $this->studioName($studio);
        $timezone = ! empty($studio->timezone) ? (string) $studio->timezone : $defaultTimezone;

        $company = $this->findCompany($bcStudioId) ?? $this->createCompany($studio, $name, $timezone);
        $company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);
        $company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_TIMEZONE->value, $timezone);

        $themeArea = ThemeArea::firstOrCreate(
            [
                'name' => $name,
                'apps_id' => $this->app->getId(),
                'companies_id' => $company->getId(),
            ],
            ['users_id' => $this->user->getId()],
        );

        $themeArea->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);

        return new StudioContext(
            bcStudioId: $bcStudioId,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: $themeArea,
            timezone: $timezone,
        );
    }

    protected function findCompany(int $bcStudioId): ?Companies
    {
        return Companies::getByCustomField(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);
    }

    protected function createCompany(stdClass $studio, string $name, string $timezone): Companies
    {
        return new CreateCompaniesAction(
            new CompanyData(
                user: $this->user,
                name: $name,
                address: $this->flattenAddress($studio),
                email: ! empty($studio->email) ? (string) $studio->email : null,
                timezone: $timezone,
                phone: ! empty($studio->phone_number) ? (string) $studio->phone_number : null,
                country_code: ! empty($studio->country_code) ? (string) $studio->country_code : null,
            ),
        )->execute();
    }

    /**
     * `community` and `locality` are both nullable and the Corporate row has neither, so the id is
     * the last resort — a company still needs a name.
     */
    protected function studioName(stdClass $studio): string
    {
        $parts = array_filter([
            $studio->community ?? null,
            $studio->locality ?? null,
        ]);

        return $parts !== [] ? implode(' — ', $parts) : 'Studio ' . (int) $studio->id;
    }

    protected function flattenAddress(stdClass $studio): ?string
    {
        $parts = array_filter([
            trim(($studio->street_number ?? '') . ' ' . ($studio->route ?? '')),
            $studio->suite ?? null,
            $studio->locality ?? null,
            $studio->administrative_area_level_1 ?? null,
            $studio->postal_code ?? null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : null;
    }
}
