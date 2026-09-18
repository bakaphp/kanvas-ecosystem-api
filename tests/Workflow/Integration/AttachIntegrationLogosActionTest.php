<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\Actions\AttachIntegrationLogosAction;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;

final class AttachIntegrationLogosActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow', 'ecosystem'];

    /**
     * An IP-literal public host skips SafeUrl's DNS lookup, so the faked responses are all the test needs.
     */
    private const string ICONS_URL = 'https://93.184.216.34/icons';
    private const string SIMPLE_ICONS_URL = 'https://93.184.216.34/simple-icons';

    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            self::ICONS_URL . '/slack-icon.svg' => Http::response(self::SVG, 200, ['Content-Type' => 'image/svg+xml']),
            self::ICONS_URL . '/stripe.svg' => Http::response(self::SVG, 200, ['Content-Type' => 'image/svg+xml']),
            self::SIMPLE_ICONS_URL . '/googlesheets' => Http::response(self::SVG, 200, ['Content-Type' => 'image/svg+xml']),
            'https://93.184.216.34/custom/*' => Http::response(self::SVG, 200, ['Content-Type' => 'image/svg+xml']),
            '*' => Http::response('', 404),
        ]);
    }

    public function testAttachesAliasedIconAndFallsBackToWordmark(): void
    {
        $slack = $this->createIntegration('slack_notifications');
        $stripe = $this->createIntegration('stripe_mcp');
        $unknown = $this->createIntegration('no_such_vendor_' . uniqid());

        $result = $this->attachLogos();

        $this->assertSame(self::ICONS_URL . '/slack-icon.svg', $result['attached']['slack_notifications #' . $slack->getId()]);
        $this->assertSame(self::ICONS_URL . '/stripe.svg', $result['attached']['stripe_mcp #' . $stripe->getId()]);
        $this->assertContains($unknown->name . ' #' . $unknown->getId(), $result['missing']);

        $logo = $slack->getFileByName(AttachIntegrationLogosAction::FIELD_NAME);
        $this->assertNotNull($logo);
        $this->assertStringNotContainsString('93.184.216.34', (string) $logo->url, 'Logos must be copied into our storage, not hotlinked');
    }

    public function testFallsBackToSimpleIconsWithDashlessSlug(): void
    {
        $sheets = $this->createIntegration('google_sheets_mcp');

        $result = $this->attachLogos();

        $this->assertSame(self::SIMPLE_ICONS_URL . '/googlesheets', $result['attached']['google_sheets_mcp #' . $sheets->getId()]);
        $this->assertNotNull($sheets->getFileByName(AttachIntegrationLogosAction::FIELD_NAME));
    }

    public function testManualLogoCoversIntegrationsNoIconSetHas(): void
    {
        $name = 'no_such_vendor_' . uniqid();
        $vendor = $this->createIntegration($name);
        $label = $name . ' #' . $vendor->getId();

        $this->assertContains($label, $this->attachLogos()['missing']);

        $customUrl = 'https://93.184.216.34/custom/' . $name . '.svg';
        $result = $this->attachLogos(logoOverrides: [$name => $customUrl]);

        $this->assertSame($customUrl, $result['attached'][$label]);
        $this->assertNotNull($vendor->getFileByName(AttachIntegrationLogosAction::FIELD_NAME));
    }

    public function testSkipsIntegrationsThatAlreadyHaveALogoUnlessOverwriting(): void
    {
        $slack = $this->createIntegration('slack_notifications');
        $label = 'slack_notifications #' . $slack->getId();

        $this->attachLogos();

        $this->assertContains($label, $this->attachLogos()['skipped']);
        $this->assertArrayHasKey($label, $this->attachLogos(overwrite: true)['attached']);
    }

    public function testIntegrationsQueryExposesTheLogo(): void
    {
        $slack = $this->createIntegration('slack_notifications');
        $this->attachLogos();

        $response = $this->graphQL('
            query($where: QueryIntegrationsWhereWhereConditions) {
                integrations(where: $where) {
                    data {
                        files {
                            data {
                                field_name
                                url
                            }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $slack->getId(),
            ],
        ])->assertSuccessful();

        $this->assertSame(
            [
                'field_name' => AttachIntegrationLogosAction::FIELD_NAME,
                'url' => $slack->getFileByName(AttachIntegrationLogosAction::FIELD_NAME)->url,
            ],
            $response->json('data.integrations.data.0.files.data.0')
        );
    }

    private function attachLogos(bool $overwrite = false, array $logoOverrides = []): array
    {
        return new AttachIntegrationLogosAction(
            app: app(Apps::class),
            user: auth()->user(),
            overwrite: $overwrite,
            logoOverrides: $logoOverrides,
            deviconsUrl: self::ICONS_URL,
            simpleIconsUrl: self::SIMPLE_ICONS_URL,
        )->execute();
    }

    private function createIntegration(string $name): Integrations
    {
        return Integrations::create([
            'apps_id' => app(Apps::class)->getId(),
            'name' => $name,
            'handler' => 'none',
            'type' => 'key',
        ]);
    }
}
