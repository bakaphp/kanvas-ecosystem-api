<?php

declare(strict_types=1);

namespace Kanvas\Locations\Actions;

class UpdateAllLocationsAction
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
        $countries = new UpdateCountriesAction();
        $countries->execute();

        $states = new UpdateStatesAction();
        $states->execute();

        $cities = new UpdateCitiesAction();
        $cities->execute();
    }
}
