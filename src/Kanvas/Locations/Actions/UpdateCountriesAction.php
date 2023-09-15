<?php

declare(strict_types=1);

namespace Kanvas\Locations\Actions;

use Kanvas\Locations\Models\Countries;

class UpdateCountriesAction
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
     * @return bool
     */
    public function execute() : bool
    {
        $i = 0;
        if (($handle = fopen(config('locations.locationsUrl.countries'), "r")) !== false) {
            while (($importData = fgetcsv($handle, 1000, ",")) !== false) {
                if ($i === 0) {
                    $i = 1;
                    continue;
                }
                // Remove the first iteration as it's not "real" datas

                Countries::updateOrCreate(
                    ['id' => $importData[0]],
                    [
                        "name" => $importData[1],
                        "code" => $importData[3],
                    ]
                );
                $i++;
            }
            fclose($handle);
            return true;
        }
    }
}
