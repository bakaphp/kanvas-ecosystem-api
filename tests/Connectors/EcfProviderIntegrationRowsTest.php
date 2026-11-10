<?php

declare(strict_types=1);

namespace Tests\Connectors;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Handlers\AlanubeHandler;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Handlers\WebPosHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

/**
 * The `integrationCompany` mutation builds the handler named on the catalog row and passes it the form the
 * row's `config` describes, so a field the row names but the handler never reads is a form that cannot work.
 * Settings go on a throwaway company: they are written to Redis, which no transaction rolls back.
 */
final class EcfProviderIntegrationRowsTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    public function testBothProvidersAreInTheCatalogWithTheirHandlers(): void
    {
        $this->assertSame(WebPosHandler::class, $this->row(IntegrationsEnum::WEBPOS)->handler);
        $this->assertSame(AlanubeHandler::class, $this->row(IntegrationsEnum::ALANUBE)->handler);
    }

    public function testTheWebPosFormFromTheCatalogSetsTheCompanyUp(): void
    {
        Http::fake(['https://fedotest-api.webposonline.com/api/fedo/ak/v1/checkRnc/*' => Http::response(['razonSocial' => 'Ejemplo SRL'])]);
        $company = $this->freshCompany();
        $form = [
            'api_key' => 'webpos-key-not-real',
            'license' => 'LICENSE1',
            'branch' => '0001',
            'pos' => '001',
            'rnc' => '1-31-79432-1',
            'environment' => 'test',
        ];

        $row = $this->row(IntegrationsEnum::WEBPOS);
        $this->assertSame(array_keys($form), array_keys((array) $row->config));
        $this->assertTrue($this->handler($row, $company, $form)->setup());

        $this->assertSame('LICENSE1', $company->get(ConfigurationEnum::COMPANY_LICENSE->value));
        $this->assertSame('webpos', $company->get('scribe-ecf-provider'));
        $this->assertSame('131794321', (string) $company->get('scribe-ecf-issuer-rnc'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/checkRnc/webpos-key-not-real/131794321'));
    }

    public function testTheAlanubeFormNeverAsksForTheCertificate(): void
    {
        $row = $this->row(IntegrationsEnum::ALANUBE);
        $fields = array_keys((array) $row->config);

        $this->assertSame(['account_mode', 'api_token', 'alanube_company_id'], $fields);

        $this->expectException(ValidationException::class);
        $this->handler($row, $this->freshCompany(), ['account_mode' => 'neither'])->setup();
    }

    private function row(IntegrationsEnum $integration): Integrations
    {
        return Integrations::query()
            ->where('name', $integration->value)
            ->where('apps_id', 0)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function handler(Integrations $row, Companies $company, array $form): BaseIntegration
    {
        $region = Regions::query()->where('apps_id', app(Apps::class)->getId())->first() ?? Regions::query()->firstOrFail();

        return new ($row->handler)(
            app: app(Apps::class),
            company: $company,
            region: $region,
            data: $form,
            integration: $row,
        );
    }
}
