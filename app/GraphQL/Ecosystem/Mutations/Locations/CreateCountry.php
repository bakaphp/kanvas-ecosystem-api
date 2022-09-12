<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Locations;

use Illuminate\Support\Arr;
use Kanvas\Locations\Countries\Models\Countries;

final class CreateCountry
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request) : Countries
    {
        // TODO implement the resolver

        $states = Arr::pull($request, 'states');
        $country = Countries::create($request);
        if ($states) {
            foreach ($states as $state) {
                $cities = Arr::pull($state, 'cities');
                $newState = $country->states()->create($state);
                if ($cities) {
                    $newState->cities()->createMany($cities);
                }
            }
        }
        return $country;
    }
}
