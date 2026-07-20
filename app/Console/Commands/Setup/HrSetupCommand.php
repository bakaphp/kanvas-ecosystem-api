<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\HumanResources\Leave\Actions\CreateLeaveTypeAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveType as LeaveTypeData;
use Kanvas\HumanResources\Leave\Enums\AccrualMethodEnum;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Kanvas\Users\Models\Users;

/**
 * Bootstraps HR for a company — seeds the default leave types so employees have something to request
 * against out of the box (without them request_leave / requestHrLeave has no valid type). Idempotent:
 * skips any leave type that already exists by name for the company.
 */
class HrSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-hr:setup {app_id} {user_id} {company_id}';

    protected $description = 'Initialize HR for a company — seed the default leave types (Vacation, Sick, Personal, Unpaid).';

    /**
     * @var list<array{name: string, isPaid: bool, accrual: AccrualMethodEnum, days: float|null, carryover: float|null, color: string}>
     */
    private const array DEFAULT_LEAVE_TYPES = [
        ['name' => 'Vacation', 'isPaid' => true, 'accrual' => AccrualMethodEnum::ANNUAL_ALLOTMENT, 'days' => 15.0, 'carryover' => 5.0, 'color' => '#3B82F6'],
        ['name' => 'Sick Leave', 'isPaid' => true, 'accrual' => AccrualMethodEnum::ANNUAL_ALLOTMENT, 'days' => 10.0, 'carryover' => null, 'color' => '#EF4444'],
        ['name' => 'Personal', 'isPaid' => true, 'accrual' => AccrualMethodEnum::ANNUAL_ALLOTMENT, 'days' => 3.0, 'carryover' => null, 'color' => '#A855F7'],
        ['name' => 'Unpaid Leave', 'isPaid' => false, 'accrual' => AccrualMethodEnum::UNLIMITED, 'days' => null, 'carryover' => null, 'color' => '#6B7280'],
    ];

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $user = Users::getById((int) $this->argument('user_id'));
        $company = Companies::getById((int) $this->argument('company_id'));

        $this->overwriteAppService($app);

        $created = 0;
        $skipped = 0;

        foreach (self::DEFAULT_LEAVE_TYPES as $definition) {
            $exists = LeaveType::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                ++$skipped;

                continue;
            }

            new CreateLeaveTypeAction(
                new LeaveTypeData(
                    app: $app,
                    company: $company,
                    user: $user,
                    name: $definition['name'],
                    isPaid: $definition['isPaid'],
                    accrualMethod: $definition['accrual'],
                    defaultAnnualDays: $definition['days'],
                    carryoverMaxDays: $definition['carryover'],
                    color: $definition['color'],
                ),
            )->execute();
            ++$created;
        }

        $this->info("HR setup for {$company->name} (app={$app->name}): {$created} leave type(s) created, {$skipped} already existed.");

        return self::SUCCESS;
    }
}
