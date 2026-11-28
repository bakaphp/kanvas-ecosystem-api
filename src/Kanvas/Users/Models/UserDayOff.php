<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Casts\Json;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Enums\UserDayOffTypeEnum;

/**
 * A stretch of time a user is not working for a company: a vacation, a single day off, or a fixed
 * weekly day off.
 *
 * `start_date`/`end_date` bound when the entry applies (null = open on that side) and `days` narrows
 * it to weekdays (null = every day). So a date range alone is a vacation, `days` alone is a weekly
 * day off, and both together is "Saturdays off during December".
 *
 * @property int                $id
 * @property int                $apps_id
 * @property int                $companies_id
 * @property int                $users_id
 * @property UserDayOffTypeEnum $type
 * @property Carbon|null        $start_date
 * @property Carbon|null        $end_date
 * @property array<int, int>|null $days Weekdays, 0 (Sunday) to 6 (Saturday)
 * @property string|null        $note
 */
class UserDayOff extends BaseModel
{
    protected $table = 'users_day_offs';

    protected $guarded = [];

    protected $casts = [
        'type' => UserDayOffTypeEnum::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'days' => Json::class,
        'is_deleted' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Compared as calendar dates, so the caller decides the timezone by the date it passes.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        $days = $this->days ?? [];

        // A row with no dates and no weekdays would take the user off every day, forever.
        if ($this->start_date === null && $this->end_date === null && $days === []) {
            return false;
        }

        $day = $date->toDateString();

        if ($this->start_date !== null && $day < $this->start_date->toDateString()) {
            return false;
        }

        if ($this->end_date !== null && $day > $this->end_date->toDateString()) {
            return false;
        }

        return $days === [] || in_array($date->dayOfWeek, array_map('intval', $days), true);
    }
}
