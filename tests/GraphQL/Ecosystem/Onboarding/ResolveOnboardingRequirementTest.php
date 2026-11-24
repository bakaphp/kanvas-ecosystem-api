<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Actions\SelectOnboardingAreasAction;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Notifications\IntegrationSetupRequestedNotification;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\Integrations;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

final class ResolveOnboardingRequirementTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private const string MUTATION = /** @lang GraphQL */ '
        mutation($input: ResolveOnboardingRequirementInput!) {
            resolveOnboardingRequirement(input: $input) {
                slug
                status
                mode
                satisfied_by
                options { label connected }
            }
        }
    ';

    private string $prefix;
    private Apps $kanvasApp;
    private Companies $company;
    private Integrations $books;
    private Integrations $alegra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'q' . substr(uniqid(), -6) . '_';
        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->books = $this->integration('books');
        $this->alegra = $this->integration('alegra');

        OnboardingSection::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'slug' => $this->prefix . 'ap',
            'label' => 'AP',
            'title' => 'AP',
            'description' => 'Description',
            'is_available' => true,
            'display_order' => 1,
            'is_active' => true,
            'config' => [
                'agents' => [
                    ['slug' => 'ap', 'name' => 'AP', 'description' => 'x', 'source_agent' => 'AP', 'agent_type' => 'AP', 'requirements' => ['accounting_software', 'product_catalog', 'bills_inbox']],
                ],
                'modules' => [],
                'requirements' => [
                    [
                        'slug' => 'accounting_software',
                        'label' => 'Your accounting software',
                        'required' => true,
                        'shared' => true,
                        'allow_not_used' => true,
                        'options' => [
                            ['label' => 'Books', 'integration_id' => $this->books->getId(), 'mode' => 'connect'],
                            ['label' => 'Alegra', 'integration_id' => $this->alegra->getId(), 'mode' => 'request_setup'],
                        ],
                    ],
                    [
                        'slug' => 'product_catalog',
                        'label' => 'Your products and prices',
                        'required' => false,
                        'shared' => true,
                        'allow_not_used' => true,
                        'allow_not_listed' => true,
                        'options' => [['label' => 'Excel / CSV', 'mode' => 'upload']],
                    ],
                    [
                        'slug' => 'bills_inbox',
                        'label' => 'Where your bills arrive',
                        'required' => true,
                        'shared' => false,
                        'allow_not_used' => false,
                        'options' => [['label' => 'Kanvas email', 'mode' => 'kanvas_provided', 'address_from' => 'agent_mailbox']],
                    ],
                ],
                'capabilities' => [],
                'setup_steps' => [],
            ],
        ]);

        $row = new OnboardingStateService()->forCompany($this->kanvasApp, $this->company, auth()->user());
        new SelectOnboardingAreasAction($row, auth()->user(), [$this->prefix . 'ap'])->execute();
    }

    public function testConnectRecordsPendingConnect(): void
    {
        $data = $this->resolve(['requirement' => 'accounting_software', 'mode' => 'CONNECT', 'integration_id' => $this->books->getId()]);

        $this->assertSame('accounting_software', $data['slug']);
        $this->assertSame('PENDING_CONNECT', $data['status']);
        $this->assertSame('CONNECT', $data['mode']);
        $this->assertSame('ap', $data['satisfied_by']);

        $answer = $this->row()->dataGet('requirements.accounting_software');
        $this->assertSame($this->books->getId(), (int) $answer['integration_id']);
        $this->assertSame(auth()->user()->getId(), (int) $answer['resolved_by']);
        $this->assertNotEmpty($answer['resolved_at']);

        $this->assertSame(1, Event::query()
            ->where('event_type', 'onboarding.requirement.resolved')
            ->where('source_entity_id', $this->row()->getId())
            ->count());
    }

    public function testRequestSetupNotifiesOwners(): void
    {
        Notification::fake();
        $owner = Users::factory()->create();
        Bouncer::scope()->onceTo(
            RolesEnums::getScope($this->kanvasApp),
            fn () => $owner->assign(RolesEnums::OWNER->value),
        );

        $data = $this->resolve([
            'requirement' => 'accounting_software',
            'mode' => 'REQUEST_SETUP',
            'integration_id' => $this->alegra->getId(),
            'notes' => 'We use the Alegra PYME plan',
        ]);

        $this->assertSame('SETUP_REQUESTED', $data['status']);
        $this->assertSame('We use the Alegra PYME plan', $this->row()->dataGet('requirements.accounting_software.notes'));

        Notification::assertSentTo(
            $owner,
            IntegrationSetupRequestedNotification::class,
            fn (IntegrationSetupRequestedNotification $notification): bool => $notification->getSubject() === 'Setup requested: Alegra for ' . $this->company->name,
        );
    }

    public function testNotUsedRequiresAllowNotUsed(): void
    {
        $response = $this->graphQL(self::MUTATION, ['input' => ['requirement' => 'bills_inbox', 'not_used' => true]]);
        $this->assertNull($response->json('data.resolveOnboardingRequirement'));
        $this->assertStringContainsString('bills_inbox', (string) $response->json('errors.0.message'));

        $data = $this->resolve(['requirement' => 'product_catalog', 'not_used' => true]);
        $this->assertSame('NOT_USED', $data['status']);
    }

    public function testUploadRequiresAFileAndRecordsIt(): void
    {
        $response = $this->graphQL(self::MUTATION, ['input' => ['requirement' => 'product_catalog', 'mode' => 'UPLOAD']]);
        $this->assertNull($response->json('data.resolveOnboardingRequirement'));

        $file = new Filesystem();
        $file->apps_id = $this->kanvasApp->getId();
        $file->companies_id = $this->company->getId();
        $file->users_id = auth()->user()->getId();
        $file->name = 'prices.csv';
        $file->path = 'files/' . $this->prefix . 'prices.csv';
        $file->url = 'https://cdn.example.test/files/' . $this->prefix . 'prices.csv';
        $file->file_type = 'csv';
        $file->size = '1024';
        $file->saveOrFail();

        $data = $this->resolve(['requirement' => 'product_catalog', 'mode' => 'UPLOAD', 'filesystem_id' => $file->getId()]);
        $this->assertSame('UPLOADED', $data['status']);
        $this->assertSame($file->getId(), (int) $this->row()->dataGet('requirements.product_catalog.filesystem_id'));
    }

    public function testToolNameRecordsNotListed(): void
    {
        $data = $this->resolve(['requirement' => 'product_catalog', 'tool_name' => 'Our own ERP']);

        $this->assertSame('NOT_LISTED', $data['status']);
        $this->assertSame('Our own ERP', $this->row()->dataGet('requirements.product_catalog.tool_name'));
    }

    public function testUnknownRequirementOrOptionIsRejected(): void
    {
        $response = $this->graphQL(self::MUTATION, ['input' => ['requirement' => 'no_such_slot', 'not_used' => true]]);
        $this->assertNull($response->json('data.resolveOnboardingRequirement'));

        $response = $this->graphQL(self::MUTATION, ['input' => ['requirement' => 'accounting_software', 'mode' => 'CONNECT', 'integration_id' => 999999999]]);
        $this->assertNull($response->json('data.resolveOnboardingRequirement'));
        $this->assertNull($this->row()->dataGet('requirements.accounting_software'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function resolve(array $input): array
    {
        return $this->graphQLData($this->graphQL(self::MUTATION, ['input' => $input]), 'resolveOnboardingRequirement');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): CompanyOnboarding
    {
        return CompanyOnboarding::forCompany($this->kanvasApp, $this->company);
    }

    private function integration(string $base): Integrations
    {
        return Integrations::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'name' => $this->prefix . $base,
            'handler' => 'none',
            'type' => 'key',
        ]);
    }
}
