<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;

class CreatePipelineAction
{
    public const DEFAULT_STAGES = [
        ['name' => 'Sent', 'slug' => 'sent', 'weight' => 1],
        ['name' => 'Open', 'slug' => 'open', 'weight' => 2],
        ['name' => 'Submitted', 'slug' => 'submitted', 'weight' => 3],
    ];

    public function __construct(
        protected readonly string $name,
        protected readonly UserInterface $user,
        protected readonly AppInterface $app,
        protected readonly int $companiesId = 0,
        protected readonly array $stages = [],
    ) {
    }

    public function execute(): Pipeline
    {
        $pipeline = new Pipeline();
        $pipeline->apps_id = $this->app->getId();
        $pipeline->companies_id = $this->companiesId;
        $pipeline->users_id = $this->user->getId();
        $pipeline->name = $this->name;
        $pipeline->slug = Str::slug($this->name);

        $stages = ! empty($this->stages) ? $this->stages : self::DEFAULT_STAGES;
        $pipeline->weight = count($stages);
        $pipeline->saveOrFail();

        foreach ($stages as $stage) {
            $pipelineStage = new PipelineStage();
            $pipelineStage->pipelines_id = $pipeline->getId();
            $pipelineStage->name = $stage['name'];
            $pipelineStage->slug = $stage['slug'];
            $pipelineStage->weight = $stage['weight'];
            $pipelineStage->saveOrFail();
        }

        return $pipeline;
    }
}
