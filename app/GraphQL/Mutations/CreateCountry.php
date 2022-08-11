<?php
namespace App\GraphQL\Mutations;

use Illuminate\Support\Arr;
use Kanvas\Locations\Countries\Models\Countries;

final class CreateCountry
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args): Countries
    {
        // TODO implement the resolver

        $states = Arr::pull($args, 'states');
        $country = Countries::create($args);
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
