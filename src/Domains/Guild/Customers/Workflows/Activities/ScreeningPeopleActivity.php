<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Workflows\Activities;

use Illuminate\Database\Eloquent\Model;
use Workflow\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\ScreeningAction;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Enums\AppEnums;

class ScreeningPeopleActivity extends Activity
{
    public $tries = 5;

    public function execute(Model $people, array $params): array
    {
        $peopleData = (new ScreeningAction($people, app(Apps::class)))->execute();
        $contactType = ContactType::firstOrCreate([
            'name' => 'LinkedIn',
            'users_id' => AppEnums::DEFAULT_USER_ID,
            'companies_id' => AppEnums::DEFAULT_COMPANY_ID
        ]);
        $people->contacts()->create([
            'contacts_types_id' => $contactType->id,
            'value' => $peopleData['linkedin'],
            'weight' => 1
        ]);
        $history = [];
        foreach($peopleData['employment_history'] as $employmentHistory) {
            $history[] = PeopleEmploymentHistory::create([
                'peoples_id' => $people->id,
                'company_employer_name' => $employmentHistory['organization_name'],
                'position' => $employmentHistory['position'],
                'start_date' => $employmentHistory['start_date'],
                'end_date' => $employmentHistory['end_date'],
                'position' => $employmentHistory['title'],
                'address' => $employmentHistory['raw_address'],
            ]);
        }
        $people->peoplesEmploymentHistory()->saveMany($history);
        return [
            'status' => 'success',
            'message' => 'People screened successfully',
            'people' => $people->id,
        ];
    }
}
