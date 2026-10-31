<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Activities\GeneratePdfActivity;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Tests\TestCase;

final class CreditAppPdfFilenameWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'social', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }
    }

    public function testDynamicMessageFilenameIsUsedForTheSingleGeneratedPdf(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $templateName = 'credit-app-filename-' . uniqid();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => $templateName,
                'template' => '<p>Credit app {{ $entity->getId() }}</p>',
            ])
        )->execute();

        $message = Message::factory()->create([
            'message' => [
                'verb' => 'credit-app',
                'data' => [
                    'form' => [
                        'personal' => [],
                        'housing' => [],
                        'financial' => [],
                    ],
                    'pdf_file_name' => 'credit-app-4.pdf',
                    'params' => [
                        'field_name' => 'credit-app-4.pdf',
                    ],
                ],
            ],
        ]);

        $rule = Rule::factory()->withAsync(false)->create([
            'systems_modules_id' => SystemModulesRepository::getByModelName(Message::class),
            'companies_id' => $company->getId(),
            'rules_types_id' => RuleType::getByName(WorkflowEnum::CREATED->value)->getId(),
            'params' => [
                'template_pdf' => $templateName,
                'pdf_file_name' => 'credit-app-default.pdf',
                'sleepTime' => 0,
            ],
        ]);

        RuleCondition::create([
            'rules_id' => $rule->getId(),
            'attribute_name' => "message['verb']",
            'operator' => '==',
            'value' => 'credit-app',
        ]);

        $action = Action::factory()->create([
            'name' => 'Generate PDF From Template',
            'model_name' => GeneratePdfActivity::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);

        $workflowAction = RuleWorkflowAction::create([
            'actions_id' => $action->getId(),
            'systems_modules_id' => SystemModulesRepository::getByModelName(Message::class),
        ]);

        RuleAction::create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $workflowAction->getId(),
            'weight' => 0,
        ]);

        $this->registerInternalIntegration($app, $company);

        $beforeFiles = $message->getFilesQueryBuilder()->count();
        $beforePdfIds = Filesystem::query()
            ->where('name', 'credit-app-4.pdf')
            ->pluck('id')
            ->all();

        (new ProcessWorkflowEventAction($app, $message))->execute(
            WorkflowEnum::CREATED->value,
            ['app' => $app, 'company' => $company]
        );

        $files = $message->getFilesQueryBuilder()->get();
        $generatedPdfs = Filesystem::query()
            ->where('name', 'credit-app-4.pdf')
            ->whereNotIn('id', $beforePdfIds)
            ->get();

        try {
            $this->assertCount($beforeFiles + 1, $files);
            $this->assertCount(1, $generatedPdfs);
            $this->assertSame('credit-app-4.pdf', $files->sole()->name);
            $this->assertSame('credit-app-4.pdf', $files->sole()->field_name);
        } finally {
            foreach ($generatedPdfs as $filesystem) {
                new FilesystemServices($app)->delete($filesystem);
            }
        }
    }

    private function registerInternalIntegration(Apps $app, $company): void
    {
        $region = Regions::getDefault($company, $app);

        if ($region === null) {
            $region = Regions::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => 0,
                'name' => 'Credit App PDF Region ' . uniqid(),
                'is_default' => 1,
                'is_deleted' => 0,
            ]);
        }

        IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $company->getId(),
                'integrations_id' => Integrations::getByName(IntegrationsEnum::INTERNAL->value)->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)
                    ->where('apps_id', 0)
                    ->firstOrFail()
                    ->getId(),
                'is_active' => 1,
            ]
        );
    }
}
