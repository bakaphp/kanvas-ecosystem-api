<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\StudioModeEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Enums\StateEnums;
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
    /** @var array<int, int> requested studio ids the source has no row for */
    public array $unknownStudioIds = [];

    /** @var array<int, int> studios left untouched because their mirror is paused */
    public array $pausedStudioIds = [];

    /**
     * Resolving writes (it creates companies, rewrites settings and renames theme areas), so a run
     * scoped by $onlyStudioIds must never touch the others, and a scheduled run passes
     * $skipPausedStudios so a studio an operator paused keeps whatever was edited in Kanvas.
     *
     * @param  array<int, int>|null  $onlyStudioIds  null resolves every studio
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected ?Companies $fallbackCompany = null,
        protected ?array $onlyStudioIds = null,
        protected bool $skipPausedStudios = false,
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
        $this->unknownStudioIds = [];
        $this->pausedStudioIds = [];

        $studios = $this->studioRows();

        foreach ($studios as $studio) {
            $bcStudioId = (int) $studio->id;

            if ($this->skipPausedStudios && self::isPaused(self::companyForStudio($this->app, $bcStudioId))) {
                $this->pausedStudioIds[] = $bcStudioId;

                continue;
            }

            $contexts[$bcStudioId] = $this->resolveStudio($studio, $defaultTimezone);
        }

        if ($this->onlyStudioIds !== null) {
            $found = $studios->map(fn (stdClass $studio) => (int) $studio->id)->all();
            $this->unknownStudioIds = array_values(array_diff(array_map('intval', $this->onlyStudioIds), $found));
        }

        return $contexts;
    }

    /**
     * @return Collection<int, stdClass>
     */
    protected function studioRows(): Collection
    {
        return new Client($this->app)->table('studios')
            ->when(
                $this->onlyStudioIds !== null,
                fn (Builder $query) => $query->whereIn('id', $this->onlyStudioIds)
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * Only an explicit "off" counts: a studio whose company has no flag yet has not been
     * provisioned, and the import is what provisions it.
     */
    public static function isPaused(?Companies $company): bool
    {
        if ($company === null) {
            return false;
        }

        $key = ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value;

        return $company->get($key) !== null && ! $company->getBool($key);
    }

    protected function resolveStudio(stdClass $studio, string $defaultTimezone): StudioContext
    {
        $bcStudioId = (int) $studio->id;
        $name = $this->studioName($studio);
        $timezone = ! empty($studio->timezone) ? (string) $studio->timezone : $defaultTimezone;

        $company = self::companyForStudio($this->app, $bcStudioId) ?? $this->createCompany($studio, $name, $timezone);

        // Only a name this action made up is replaced; one an operator chose in Kanvas is kept.
        if ($company->name === self::fallbackName($bcStudioId) && $company->name !== $name) {
            $company->name = $name;
            $company->saveOrFail();
        }

        $company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);
        $company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_TIMEZONE->value, $timezone);

        // By studio id first: a studio whose name changed (studio 0 went from "Studio 0" to
        // "Corporate") would otherwise get a second area carrying the same studio id.
        $themeArea = self::themeAreaForStudio($this->app, $company, $bcStudioId)
            ?? ThemeArea::firstOrCreate(
                [
                    'name' => $name,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $company->getId(),
                ],
                ['users_id' => $this->user->getId()],
            );

        if ($themeArea->name !== $name) {
            $themeArea->name = $name;
            $themeArea->saveOrFail();
        }

        $themeArea->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);

        return new StudioContext(
            bcStudioId: $bcStudioId,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: $themeArea,
            timezone: $timezone,
        );
    }

    /**
     * Company settings carry no app, so the studio id alone would hand one app's company to another
     * app importing the same BrushCrazy database. Scoped through the company's app membership.
     */
    public static function companyForStudio(AppInterface $app, int $bcStudioId): ?Companies
    {
        return Companies::getByCustomFieldBuilder(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId)
            ->whereIn(
                'companies.id',
                fn (Builder $members) => $members->select('companies_id')
                    ->from('user_company_apps')
                    ->where('apps_id', $app->getId())
                    ->where('is_deleted', StateEnums::NO->getValue())
            )
            ->orderBy('companies.id')
            ->first();
    }

    /**
     * Shared with the webhook. Not "the company's theme area": the lookup seed gives every studio
     * company an Unassigned area too.
     */
    public static function themeAreaForStudio(AppInterface $app, Companies $company, int $bcStudioId): ?ThemeArea
    {
        return ThemeArea::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value,
            $bcStudioId,
            $company
        )
            ->where('apps_id', $app->getId())
            ->orderBy('id')
            ->first();
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
     * The Corporate row (studio 0) has no `community` or `locality`; BrushCrazy keeps the word
     * "Corporate" in `administrative_area_level_1`, the state column of a real studio. The id is the
     * last resort — a company still needs a name.
     */
    protected function studioName(stdClass $studio): string
    {
        $parts = array_filter([
            $studio->community ?? null,
            $studio->locality ?? null,
        ]);

        if ($parts !== []) {
            return implode(' — ', $parts);
        }

        return ! empty($studio->administrative_area_level_1)
            ? (string) $studio->administrative_area_level_1
            : self::fallbackName((int) $studio->id);
    }

    protected static function fallbackName(int $bcStudioId): string
    {
        return 'Studio ' . $bcStudioId;
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
