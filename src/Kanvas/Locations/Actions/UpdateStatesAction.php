<?php

declare(strict_types=1);

namespace Kanvas\Locations\Actions;

use Kanvas\Locations\Models\States;

class UpdateStatesAction
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
        if (($handle = fopen(storage_path("locations/states.csv"), "r")) !== false) {
            while (($importData = fgetcsv($handle, 1000, ",")) !== false) {
                if ($i === 0) {
                    $i = 1;
                    continue;
                }
                // Remove the first iteration as it's not "real" data

                States::updateOrCreate(
                    ['id' => $importData[0]],
                    [
                        "countries_id" => $importData[2],
                        "name" => $importData[1],
                        "code" => $importData[5],
                    ]
                );
                $i++;
            }
            fclose($handle);
        }
    }
}
