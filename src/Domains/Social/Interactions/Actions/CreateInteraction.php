<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Actions;

use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\Models\Interactions;

class CreateInteraction
{
    public function __construct(
        protected Interaction $interaction,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Interactions
    {
        /**
         * @var Interactions
         */
        return Interactions::firstOrCreate([
            'apps_id' => $this->interaction->app->getId(),
            'name' => $this->interaction->name,
        ], [
            'title' => $this->interaction->title,
            'description' => $this->interaction->description,
        ]);
    }
}
