<?php

declare(strict_types=1);

namespace Tests\Connectors\Alanube;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Connectors\Alanube\Actions\ConnectAlanubeCompanyAction;
use Kanvas\Connectors\Alanube\Actions\OnboardAlanubeCompanyAction;
use Kanvas\Connectors\Alanube\Actions\RunAlanubeCertificationAction;
use Kanvas\Connectors\Alanube\Actions\SendAlanubeCommercialResponseAction;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCertificate;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCompanyProfile;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Exceptions\AlanubeRequestException;
use Kanvas\Exceptions\ValidationException;
use LogicException;
use Tests\Connectors\Traits\HasAlanubeConfiguration;
use Tests\TestCase;

final class AlanubeOnboardingTest extends TestCase
{
    use DatabaseTransactions;
    use HasAlanubeConfiguration;

    private const string CERTIFICATE_BYTES = "\x30\x82\x0a\x1fSYNTHETIC-P12-NOT-A-REAL-CERTIFICATE";
    private const string CERTIFICATE_PASSWORD = 'synthetic-cert-pass-7731';
    private const string NEW_COMPANY_ID = '01HZZZNEWASSOCIATED00000000';

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://graphapi.kanvas.dev']);
        $this->registerAlanubeWorkflowActions();
    }

    public function testOnboardingPassesTheCertificateThroughAndStoresOnlyTheCompanyId(): void
    {
        $company = $this->freshKanvasModeCompany();
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message . ' ' . json_encode($event->context);
        });
        $this->fakeAlanubeCompanyEndpoint();

        $result = $this->onboard($company)->execute();

        $this->assertTrue($result->verified);
        $this->assertSame(self::NEW_COMPANY_ID, $company->get(ConfigurationEnum::COMPANY_ID->value));
        $this->assertSame('alanube', $company->get('scribe-ecf-provider'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::ALANUBE_SANDBOX . 'company'
                && $request['type'] === 'associated'
                && $request['certificate']['content'] === base64_encode(self::CERTIFICATE_BYTES)
                && $request['certificate']['password'] === self::CERTIFICATE_PASSWORD
                && isset($request['webhooks']['documents']['emissionFinished']['url']);
        });

        $this->assertCertificateIsNowhere($company);

        $allLogs = implode("\n", $logged);
        $this->assertStringNotContainsString(self::CERTIFICATE_PASSWORD, $allLogs);
        $this->assertStringNotContainsString(base64_encode(self::CERTIFICATE_BYTES), $allLogs);
    }

    public function testAFailedOnboardingSurfacesAlanubesAnswerButNeverTheCertificate(): void
    {
        $company = $this->freshKanvasModeCompany();
        Http::fake(fn () => Http::response(['message' => ['certificate password is invalid'], 'code' => 'AP1001'], 400));

        try {
            $this->onboard($company)->execute();
            $this->fail('Expected Alanube to refuse the company.');
        } catch (AlanubeRequestException $e) {
            $this->assertStringContainsString('certificate password is invalid', $e->getMessage());
            $this->assertStringNotContainsString(self::CERTIFICATE_PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(base64_encode(self::CERTIFICATE_BYTES), $e->getMessage());
        }

        $this->assertNull($company->get(ConfigurationEnum::COMPANY_ID->value));
        $this->assertCertificateIsNowhere($company);
    }

    public function testTheCertificateCannotBeSerializedOrDumped(): void
    {
        $certificate = new AlanubeCertificate(self::CERTIFICATE_BYTES, self::CERTIFICATE_PASSWORD);

        $this->assertStringNotContainsString(self::CERTIFICATE_PASSWORD, print_r($certificate, true));
        $this->assertStringNotContainsString(self::CERTIFICATE_PASSWORD, var_export($certificate->__debugInfo(), true));

        $this->expectException(LogicException::class);
        serialize($certificate);
    }

    public function testAnOnboardedCompanyIsNotOnboardedTwice(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::KANVAS);
        Http::fake();

        $this->expectException(ValidationException::class);

        $this->onboard($company)->execute();
    }

    public function testConnectingAnOwnAccountReadsTheSenderFromAlanubeAndBecomesTheProvider(): void
    {
        $company = $this->freshCompany();
        $this->fakeAlanubeCompanyEndpoint(existing: true);

        $result = new ConnectAlanubeCompanyAction(
            app: app(Apps::class),
            company: $company,
            mode: AccountModeEnum::OWN,
            token: self::ALANUBE_OWN_TOKEN,
        )->execute();

        $this->assertTrue($result->verified);
        $this->assertSame(AccountModeEnum::OWN->value, $company->get(ConfigurationEnum::ACCOUNT_MODE->value));
        $this->assertSame(self::NEW_COMPANY_ID, $company->get(ConfigurationEnum::COMPANY_ID->value));
        $this->assertSame('130000001', $company->get(ConfigurationEnum::SENDER_PROFILE->value)['rnc']);
        $this->assertSame('alanube', $company->get('scribe-ecf-provider'));
        $this->assertTrue($company->isSecret(ConfigurationEnum::API_TOKEN->value));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ' . self::ALANUBE_OWN_TOKEN));
    }

    public function testTheSetupCommandOnboardsWithoutEchoingTheCertificateOrPassword(): void
    {
        $company = $this->freshCompany();
        $this->fakeAlanubeCompanyEndpoint();
        $path = tempnam(sys_get_temp_dir(), 'alanube-cert-');
        file_put_contents($path, self::CERTIFICATE_BYTES);

        try {
            $this->artisan('kanvas:alanube-setup', [
                'app_id' => app(Apps::class)->getId(),
                'company_id' => $company->getId(),
                '--mode' => 'kanvas',
                '--certificate-path' => $path,
                '--certificate-password' => self::CERTIFICATE_PASSWORD,
                '--rnc' => '130000001',
                '--address' => 'Av. Winston Churchill 1',
            ])
                ->doesntExpectOutputToContain(self::CERTIFICATE_PASSWORD)
                ->expectsOutputToContain(self::NEW_COMPANY_ID)
                ->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertSame(AccountModeEnum::KANVAS->value, $company->get(ConfigurationEnum::ACCOUNT_MODE->value));
        $this->assertSame('alanube', $company->get('scribe-ecf-provider'));
        $this->assertCertificateIsNowhere($company);
    }

    public function testCertificationStartsTheSetTestForTheAssociatedCompany(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::KANVAS);
        Http::fake([
            self::ALANUBE_SANDBOX . 'set-tests' => Http::response(['id' => '01SETTEST', 'status' => 'REGISTERED'], 201),
            self::ALANUBE_SANDBOX . 'check-set-tests/01SETTEST/idCompany/' . self::ALANUBE_COMPANY_ID => Http::response(['id' => '01SETTEST', 'status' => 'FINISHED']),
        ]);

        $setTest = new RunAlanubeCertificationAction(
            app: app(Apps::class),
            company: $company,
            itemExample: ['billingIndicator' => 1, 'itemName' => 'Consultoria', 'goodServiceIndicator' => 2, 'unitPriceItem' => 2500],
        )->execute();

        $this->assertSame('01SETTEST', $setTest['id']);
        $this->assertSame('FINISHED', RunAlanubeCertificationAction::status(app(Apps::class), $company)['status']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ALANUBE_SANDBOX . 'set-tests'
            && $request['idCompany'] === self::ALANUBE_COMPANY_ID);
    }

    public function testACommercialRejectionFindsTheDocumentByVendorAndEncfAndSendsTheReason(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        Http::fake([
            self::ALANUBE_SANDBOX . 'received-documents?*' => Http::response(['documents' => [
                ['id' => '01RECEIVED', 'documentNumber' => 'E310000009001', 'issuerIdentification' => '131000009'],
            ]]),
            self::ALANUBE_SANDBOX . 'received-documents/01RECEIVED/commercial-response' => Http::response(['commercialApprovalInfo' => ['id' => '01APPROVAL']]),
        ]);

        $approvalId = new SendAlanubeCommercialResponseAction(
            app: app(Apps::class),
            company: $company,
            vendorTaxId: '131-00000-9',
            encf: 'E310000009001',
            accepted: false,
            rejectionReason: 'No corresponde a ninguna orden de compra',
        )->execute();

        $this->assertSame('01APPROVAL', $approvalId);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['commercialResponse'] === 'REJECTED'
            && $request['notAcceptedDetail'] === 'No corresponde a ninguna orden de compra');
    }

    private function onboard(Companies $company): OnboardAlanubeCompanyAction
    {
        return new OnboardAlanubeCompanyAction(
            app: app(Apps::class),
            company: $company,
            profile: new AlanubeCompanyProfile(
                rnc: '130000001',
                companyName: 'Empresa de Prueba SRL',
                address: 'Av. Winston Churchill 1',
                mail: 'facturas@example.com',
            ),
            certificate: new AlanubeCertificate(self::CERTIFICATE_BYTES, self::CERTIFICATE_PASSWORD),
        );
    }

    private function freshCompany(): Companies
    {
        /** @var Companies $company */
        $company = Companies::factory()->create(['users_id' => Auth::id(), 'is_active' => 1]);
        $company->associateApp(app(Apps::class));
        config(['services.alanube.main_token_test' => self::ALANUBE_MAIN_TOKEN]);

        return $company;
    }

    private function freshKanvasModeCompany(): Companies
    {
        $company = $this->freshCompany();
        $company->set(ConfigurationEnum::ACCOUNT_MODE->value, AccountModeEnum::KANVAS->value);

        return $company;
    }

    /**
     * POST /company creates; GET/PATCH /company[/{id}] read and update, holding the webhooks like Alanube does.
     */
    private function fakeAlanubeCompanyEndpoint(bool $existing = false): void
    {
        $held = [];
        $record = [
            'id' => self::NEW_COMPANY_ID,
            'name' => 'Empresa de Prueba SRL',
            'identification' => '130000001',
            'address' => 'Av. Winston Churchill 1',
        ];

        Http::fake(function (Request $request) use (&$held, $record, $existing) {
            if ($request->method() === 'POST') {
                $held = $request['webhooks'] ?? [];

                return Http::response(['company' => $record], 201);
            }

            if ($request->method() === 'PATCH') {
                $held = array_replace_recursive($held, $request['webhooks']);
            }

            return $existing || $held !== []
                ? Http::response(['company' => $record + ['webhooks' => $held]])
                : Http::response(['message' => 'No se ha encontrado el recurso'], 404);
        });
    }

    private function assertCertificateIsNowhere(Companies $company): void
    {
        $settings = CompaniesSettings::query()
            ->where('companies_id', $company->getId())
            ->pluck('value')
            ->map(fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value))
            ->implode("\n");

        foreach ([self::CERTIFICATE_PASSWORD, base64_encode(self::CERTIFICATE_BYTES), 'SYNTHETIC-P12'] as $secret) {
            $this->assertStringNotContainsString($secret, $settings);
            $this->assertStringNotContainsString($secret, (string) json_encode($company->getAll(fromRedis: true)));
        }

        // An encrypted copy would pass the plain-text checks above, so no secret setting may exist beyond ours.
        $allowedSecrets = [
            ConfigurationEnum::API_TOKEN->value,
            ConfigurationEnum::WEBHOOK_SECRET->value,
            ConfigurationEnum::WEBHOOK_PREVIOUS_SECRET->value,
        ];
        foreach (CompaniesSettings::query()->where('companies_id', $company->getId())->pluck('name') as $name) {
            if ($company->isSecret($name)) {
                $this->assertContains($name, $allowedSecrets);
            }
        }
    }
}
