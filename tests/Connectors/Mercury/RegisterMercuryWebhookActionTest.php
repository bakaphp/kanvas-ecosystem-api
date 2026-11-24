<?php

declare(strict_types=1);

namespace Tests\Connectors\Mercury;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mercury\Actions\RegisterMercuryWebhookAction;
use Kanvas\Connectors\Mercury\Services\MercuryWebhookService;
use Kanvas\Connectors\Mercury\Webhooks\ProcessMercuryWebhookJob;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class RegisterMercuryWebhookActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'workflow'];

    /** Receivers live under the v1 API prefix; a URL without it 404s and Mercury never retries a 4xx. */
    public function testMercuryIsToldTheVersionedReceiverUrl(): void
    {
        config(['app.url' => 'https://graphapi.kanvas.dev']);
        WorkflowAction::firstOrCreate(
            ['model_name' => ProcessMercuryWebhookJob::class],
            ['name' => class_basename(ProcessMercuryWebhookJob::class)],
        );
        $company = $this->freshCompany();

        $service = new class () extends MercuryWebhookService {
            public ?string $registeredUrl = null;

            public function __construct()
            {
            }

            public function register(string $url): array
            {
                $this->registeredUrl = $url;

                return [];
            }

            public function registeredWebhookId(): ?string
            {
                return null;
            }
        };

        $receiver = new RegisterMercuryWebhookAction(
            app(Apps::class),
            $company,
            auth()->user(),
            $service,
        )->execute();

        $this->assertSame('https://graphapi.kanvas.dev/v1/receiver/' . $receiver->uuid, $service->registeredUrl);
    }
}
