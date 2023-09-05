<?php

declare(strict_types=1);

namespace Kanvas\Locations\Actions;

use Kanvas\Locations\Models\Cities;

class UpdateCitiesAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
    ) {
    }

    /**
     * execute.
     *
     * @return
     */
    public function execute()
    {
        $i = 0;
        if (($handle = fopen(storage_path("locations/cities.csv"), "r")) !== false) {
        
            while (($importData = fgetcsv($handle, 1000, ",")) !== false) {
                if($i === 0) {
                    $i = 1;
                    continue;
                }
                // Remove the first iteration as it's not "real" datas

                Cities::updateOrCreate(
                    ['id' => $importData[0]],
                    [
                        "states_id" => $importData[2],
                        "countries_id" => $importData[5],
                        "name" => $importData[1],
                        "latitude" => $importData[8],
                        "longitude" => $importData[9],
                    ]
                );
                $i++;
            }
            fclose($handle);
        }
    }
}
