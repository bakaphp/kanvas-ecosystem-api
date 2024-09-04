<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Social\Reactions\Actions\CreateReactionAction;
use Kanvas\Social\Reactions\DataTransferObject\Reaction as ReactionDto;

class CreateGlobalReaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-global-reaction {name} {icon} {apps}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Global Reaction ';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $reactionDto = ReactionDto::from([
            'name' => $this->argument('name'),
            'icon' => $this->argument('icon'),
            'apps' => Apps::find($this->argument('apps')),
            'companies' => Companies::find(AppEnums::GLOBAL_COMPANY_ID->getValue()),
        ]);

        $action = new CreateReactionAction($reactionDto);
        $reaction = $action->execute();
        echo 'Reaction created with id: ' . $reaction->getId() . PHP_EOL;
    }
}
