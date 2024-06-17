<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Apollo\Actions\ScreeningAction;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Locations\Models\Countries;
use Workflow\Activity;

class ScreeningPeopleActivity extends Activity
{
    public $tries = 1;

    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $peopleData = (new ScreeningAction($people, $app))->execute();
        $history = [];
        foreach ($peopleData['employment_history'] as $employmentHistory) {
            PeopleEmploymentHistory::firstOrCreate([
                'status' => (int)$employmentHistory['current'],
                'company_name' => $employmentHistory['organization_name'],
                'start_date' => $employmentHistory['start_date'],
                'end_date' => $employmentHistory['end_date'],
                'position' => $employmentHistory['title'],
                'company_address' => $employmentHistory['raw_address'],
                'peoples_id' => $people->id,
            ]);
        }
        $country = Countries::where('name', $peopleData['country'])->first();
        $address = Address::firstOrCreate([
            'peoples_id' => $people->id,
            'address' => ' ',
            'address_2' => ' ',
            'city' => $peopleData['city'],
            'state' => $peopleData['state'],
            'county' => '',
            'zip' => ' ',
            'city_id' => 0,
            'state_id' => 0,
            'countries_id' => $country->getId(),
        ]);

        return [
            'status' => 'success',
            'message' => 'People screened successfully',
            'people' => $people->id,
        ];
    }
}
