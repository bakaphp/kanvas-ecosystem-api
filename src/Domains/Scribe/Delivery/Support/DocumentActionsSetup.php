<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Support;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStageMessage;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;

/**
 * The ActionEngine setup, narrowed to the three Scribe document actions: same global action rows,
 * company actions, pipelines and message types, minus the industry defaults and the dummy engagements
 * (an all-zero lead row means nothing on a document pipeline).
 *
 * Idempotent: every row is firstOrCreate, a stage missing from an older pipeline is added, and the
 * `new_action_page_v4_config` paths are only written for slugs that have none, so a page path a tenant
 * customised survives a re-run.
 */
class DocumentActionsSetup extends Setup
{
    public const string PAGE_CONFIG_KEY = 'new_action_page_v4_config';

    public function run(): bool
    {
        DB::connection('action_engine')->transaction(function (): void {
            $this->createSystemModules();
            $actions = $this->importIndustryActions();
            $this->createCompanyActionsAndPipelines($actions);
            $this->createMessageTypes($actions);
            $this->activateCompanyActions();
        });

        $this->ensurePagePaths();

        return true;
    }

    protected function getIndustryActionsTemplate(): array
    {
        return array_map(
            static fn (DocumentActionEnum $action): array => [
                'name' => $action->value,
                'title' => $action->title(),
                'description' => $action->title(),
                'form_fields' => '',
                'form_config' => '',
                'enable' => true,
            ],
            DocumentActionEnum::cases(),
        );
    }

    protected function getPipelineTemplates(): array
    {
        $stages = [];
        foreach (DocumentActionEnum::stages() as $weight => $status) {
            $stages[] = [
                'name' => $status->value,
                'slug' => $status->value,
                'weight' => $weight + 1,
                'message' => "contact ~ ' " . $status->value . " ' ~ postTitle",
                'notification' => "contact ~ ' " . $status->value . " the ' ~ postTitle ~ ' ' ~ shareDate ~ '.'",
            ];
        }

        $templates = ['default' => $stages];
        foreach (DocumentActionEnum::cases() as $action) {
            $templates[$action->value] = $stages;
        }

        return $templates;
    }

    /**
     * The parent keys the pipeline on the acting user too, so a re-run by someone else would fork it;
     * Pipeline::getBySlug() then picks either. Keyed on the slug alone here.
     */
    protected function createPipeline(Action $action, array $stageTemplates): Pipeline
    {
        $pipeline = Pipeline::firstOrCreate(
            [
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'slug' => $action->slug,
                'is_deleted' => 0,
            ],
            [
                'users_id' => $this->user->getId(),
                'name' => $action->name,
                'weight' => count($stageTemplates),
            ]
        );

        foreach ($stageTemplates as $stageTemplate) {
            $stage = PipelineStage::firstOrCreate(
                [
                    'pipelines_id' => $pipeline->getId(),
                    'slug' => $stageTemplate['slug'],
                ],
                [
                    'name' => $stageTemplate['name'],
                    'weight' => $stageTemplate['weight'],
                    'has_rotting_days' => false,
                    'rotting_days' => 0,
                ]
            );

            PipelineStageMessage::firstOrCreate(
                ['pipelines_stages_id' => $stage->getId()],
                [
                    'message' => $stageTemplate['message'],
                    'message_notification' => $stageTemplate['notification'],
                ]
            );
        }

        return $pipeline;
    }

    /**
     * The parent creates company actions inactive (industry actions are opt-in); a document action is
     * the delivery path itself, so it is on from the start.
     */
    private function activateCompanyActions(): void
    {
        foreach (DocumentActionEnum::cases() as $action) {
            $pipeline = Pipeline::getBySlug($action->value, $this->app, $this->company);

            $pipeline->companyActions()
                ->where('companies_id', $this->company->getId())
                ->update(['is_active' => 1]);
        }
    }

    private function ensurePagePaths(): void
    {
        /** @var Companies $company */
        $company = $this->company;
        $config = $company->get(self::PAGE_CONFIG_KEY);
        $config = is_array($config) ? $config : [];

        $changed = false;
        foreach (DocumentActionEnum::cases() as $action) {
            if (! array_key_exists($action->value, $config)) {
                $config[$action->value] = $action->pagePath();
                $changed = true;
            }
        }

        if ($changed) {
            $company->set(self::PAGE_CONFIG_KEY, $config);
        }
    }
}
