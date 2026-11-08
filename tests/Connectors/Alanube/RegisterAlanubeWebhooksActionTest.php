<?php

declare(strict_types=1);

namespace Tests\Connectors\Alanube;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Actions\RegisterAlanubeWebhooksAction;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeWebhookRegistration;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Enums\WebhookEventEnum;
use Kanvas\Connectors\Alanube\Services\AlanubeWebhookSecretService;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\Connectors\Traits\HasAlanubeConfiguration;
use Tests\TestCase;

final class RegisterAlanubeWebhooksActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasAlanubeConfiguration;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    /** @var array<string, mixed> what the fake Alanube currently holds */
    private array $held = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://graphapi.kanvas.dev']);
        $this->registerAlanubeWorkflowActions();
    }

    public function testFirstRunCreatesFourReceiversAndRegistersAllFourWebhooksWithTheSecret(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::KANVAS);
        $this->fakeAlanube();

        $result = $this->register($company);

        $this->assertSame(AlanubeWebhookRegistration::UPDATED, $result->outcome);
        $this->assertTrue($result->verified);
        $this->assertSame(4, $this->receivers($company)->count());

        $secret = new AlanubeWebhookSecretService($company)->current();
        $this->assertNotNull($secret);

        Http::assertSent(function (Request $request) use ($secret, $result): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $this->assertSame(self::ALANUBE_SANDBOX . 'company/' . self::ALANUBE_COMPANY_ID, $request->url());

            foreach (WebhookEventEnum::cases() as $event) {
                $webhook = $request['webhooks'][$event->group()][$event->key()];
                $this->assertSame($result->receiverUrls[$event->value], $webhook['url']);
                $this->assertSame('active', $webhook['status']);
                $this->assertSame($secret, $webhook['headers'][ConfigurationEnum::WEBHOOK_SECRET_HEADER]);
            }

            return true;
        });
    }

    public function testRerunningKeepsTheReceiversAndDoesNotPatchWhatAlanubeAlreadyHolds(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        $this->fakeAlanube();

        $first = $this->register($company);
        $secret = new AlanubeWebhookSecretService($company)->current();
        $second = $this->register($company);

        $this->assertSame(AlanubeWebhookRegistration::UNCHANGED, $second->outcome);
        $this->assertTrue($second->verified);
        $this->assertSame($first->receiverUrls, $second->receiverUrls);
        $this->assertSame(4, $this->receivers($company)->count());
        $this->assertSame($secret, new AlanubeWebhookSecretService($company)->current());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ALANUBE_SANDBOX . 'company');
    }

    public function testAWebhookChangedAtAlanubeIsRepairedOnTheNextRun(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        $this->fakeAlanube();
        $this->register($company);

        $this->held['documents']['reception']['url'] = 'https://someone-else.example/hook';

        $result = $this->register($company);

        $this->assertSame(AlanubeWebhookRegistration::UPDATED, $result->outcome);
        $this->assertTrue($result->verified);
    }

    public function testVerificationFailsWhenAlanubeDoesNotKeepWhatWasSent(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        Http::fake([self::ALANUBE_SANDBOX . 'company' => Http::response(['company' => ['id' => self::ALANUBE_COMPANY_ID, 'webhooks' => []]])]);

        $result = $this->register($company);

        $this->assertFalse($result->verified);
        $this->assertContains('documents.emissionFinished missing', $result->mismatches);
        $this->assertNotNull($result->reason);
    }

    public function testRotationAlwaysPatchesAndTheOldSecretStaysValidForTheGraceWindow(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        $this->fakeAlanube();
        $this->register($company);
        $old = (string) new AlanubeWebhookSecretService($company)->current();

        $result = new RegisterAlanubeWebhooksAction(app(Apps::class), $company, rotateSecret: true)->execute();
        $secrets = new AlanubeWebhookSecretService($company);
        $new = (string) $secrets->current();

        $this->assertSame(AlanubeWebhookRegistration::UPDATED, $result->outcome);
        $this->assertNotSame($old, $new);
        $this->assertTrue($secrets->matches($new));
        $this->assertTrue($secrets->matches($old));

        Carbon::setTestNow(Carbon::now()->addHours(AlanubeWebhookSecretService::ROTATION_GRACE_HOURS + 1));

        try {
            $this->assertFalse($secrets->matches($old));
            $this->assertTrue($secrets->matches($new));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testALocalHostSkipsRegistrationInsteadOfFailingSetup(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        Http::fake();

        $result = $this->register($company);

        $this->assertSame(AlanubeWebhookRegistration::SKIPPED, $result->outcome);
        $this->assertFalse($result->verified);
        $this->assertSame([], new RegisterAlanubeWebhooksAction(app(Apps::class), $company)->webhooksBlock());
        Http::assertNothingSent();
    }

    private function register(Companies $company): AlanubeWebhookRegistration
    {
        return new RegisterAlanubeWebhooksAction(app(Apps::class), $company)->execute();
    }

    /**
     * @return Collection<int, ReceiverWebhook>
     */
    private function receivers(Companies $company): Collection
    {
        return ReceiverWebhook::query()
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->get();
    }

    /**
     * A stateful Alanube: GET returns what the last PATCH stored, and PATCH merges like the docs describe.
     */
    private function fakeAlanube(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->held = array_replace_recursive($this->held, $request['webhooks']);

                return Http::response(['company' => ['id' => self::ALANUBE_COMPANY_ID]]);
            }

            return Http::response(['company' => ['id' => self::ALANUBE_COMPANY_ID, 'webhooks' => $this->held]]);
        });
    }
}
