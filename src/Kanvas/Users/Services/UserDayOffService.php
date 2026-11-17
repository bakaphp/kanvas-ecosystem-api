<?php

declare(strict_types=1);

namespace Kanvas\Users\Services;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Enums\UserDayOffTypeEnum;
use Kanvas\Users\Models\UserDayOff;
use Kanvas\Users\Models\Users;

class UserDayOffService
{
    /** How far ahead a return date is searched before giving up (an open-ended absence has none). */
    private const int MAX_DAYS_AHEAD = 366;

    /** @var Collection<int, UserDayOff>|null */
    private ?Collection $entries = null;

    public function __construct(
        private readonly Users $user,
        private readonly Companies $company,
        private readonly Apps $app,
    ) {
    }

    /**
     * The entry that takes the user off on that date, a vacation winning over a day off.
     */
    public function getForDate(CarbonInterface $date): ?UserDayOff
    {
        $covering = $this->entries()->filter(
            fn (UserDayOff $entry): bool => $entry->coversDate($date)
        );

        return $covering->first(
            fn (UserDayOff $entry): bool => $entry->type === UserDayOffTypeEnum::VACATION
        ) ?? $covering->first();
    }

    /**
     * First date from $from onward (inclusive) the user is not off, or null when none is in reach.
     *
     * @param Closure(CarbonInterface): bool|null $companyIsOpen Also skips the dates the company is closed
     */
    public function getNextWorkingDate(CarbonInterface $from, ?Closure $companyIsOpen = null): ?CarbonInterface
    {
        $cursor = $from->copy()->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS_AHEAD; $i++) {
            if ($this->getForDate($cursor) === null && ($companyIsOpen === null || $companyIsOpen($cursor))) {
                return $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return null;
    }

    /**
     * @return Collection<int, UserDayOff>
     */
    private function entries(): Collection
    {
        return $this->entries ??= UserDayOff::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('users_id', $this->user->getId())
            ->notDeleted()
            ->get();
    }
}
