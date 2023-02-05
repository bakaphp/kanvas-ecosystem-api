<?php
declare(strict_types=1);

namespace Kanvas\Social\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Social\Enums\StateEnums;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class Setup
{
    /**
     * Constructor.
     *
     * @param AppInterface $app
     * @param UserInterface $user
     * @param CompanyInterface $company
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Setup all the default inventory data for this current company.
     *
     * @return bool
     */
    public function run() : bool
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);
        $createSystemModule->execute(Interactions::class);

        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::LIKE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::LIKE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();

        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::SAVE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::SAVE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::REACTION->getValue(),
                $this->app,
                ucfirst((string) StateEnums::REACTION->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::FOLLOW->getValue(),
                $this->app,
                ucfirst((string) StateEnums::FOLLOW->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::COMMENT->getValue(),
                $this->app,
                ucfirst((string) StateEnums::COMMENT->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::SHARE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::SHARE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();

        return $defaultInteraction instanceof Interactions;
    }
}
