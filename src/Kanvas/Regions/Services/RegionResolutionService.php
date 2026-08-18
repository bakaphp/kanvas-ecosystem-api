<?php

declare(strict_types=1);

namespace Kanvas\Regions\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\IPInfo;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Regions\Enums\ConfigurationEnum;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Throwable;

class RegionResolutionService
{
    public function __construct(
        protected readonly AppInterface $app,
    ) {
    }

    public function resolve(Request $request, ?UserInterface $user): ?Regions
    {
        return $this->fromUserProfile($user)
            ?? $this->fromGeoIp($request)
            ?? $this->fromAppDefault();
    }

    /**
     * A company's default region is a custom field pointing at either one of its own regions
     * or an app-global one (companies_id = 0) — the regions table itself can't express
     * "this global region is my default", since is_default there is app-wide.
     */
    public function forCompany(CompanyInterface $company): ?Regions
    {
        $regionId = $company->get(CustomFieldEnum::DEFAULT_REGION_ID->value);

        if (! empty($regionId)) {
            $region = Regions::query()
                ->fromApp($this->app)
                ->notDeleted()
                ->where('id', (int) $regionId)
                ->whereIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $company->getId()])
                ->first();

            if ($region !== null) {
                return $region;
            }
        }

        return Regions::getDefault($company, $this->app);
    }

    protected function fromUserProfile(?UserInterface $user): ?Regions
    {
        if ($user === null) {
            return null;
        }

        try {
            $regionId = $user->get(CustomFieldEnum::DEFAULT_REGION_ID->value);
            if (empty($regionId)) {
                return null;
            }

            return Regions::getById((int) $regionId, $this->app);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function fromGeoIp(Request $request): ?Regions
    {
        try {
            $ip = IPInfo::getClientIp($request);

            if ($this->isPrivateIp($ip)) {
                Log::debug('RegionResolutionService: skipping private IP', ['ip' => $ip]);

                return null;
            }

            $cacheKey = "ipinfo:country:{$ip}";
            $countryCode = Cache::get($cacheKey);

            if ($countryCode === null) {
                $info = new IPInfo()->getIpInfo($ip);
                if ($info === null) {
                    return null;
                }

                $countryCode = $info['country'] ?? null;
                if ($countryCode === null) {
                    return null;
                }

                Cache::put($cacheKey, $countryCode, 86400);
            }

            $map = $this->getRegionCountryMap();
            $regionUuid = $map[$countryCode] ?? null;
            if ($regionUuid === null) {
                return null;
            }

            return Regions::getByUuid($regionUuid, $this->app);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function fromAppDefault(): ?Regions
    {
        try {
            $defaultUuid = $this->app->get(ConfigurationEnum::DEFAULT_REGION_UUID->value);
            if (empty($defaultUuid)) {
                return null;
            }

            return Regions::getByUuid($defaultUuid, $this->app);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function getRegionCountryMap(): array
    {
        $map = $this->app->get(ConfigurationEnum::REGION_COUNTRY_MAP->value);

        return is_array($map) ? $map : [];
    }

    protected function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
