<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration;

use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Services\AdminLinkService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class AdminLinkServiceTest extends TestCase
{
    private const HOST = 'https://admin.kanvas.dev';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kanvas.app.frontend_url', self::HOST);
    }

    public function testBuildsADetailLink(): void
    {
        $app = app(Apps::class);

        $url = new AdminLinkService()->build(
            $app,
            AdminLinkSectionEnum::LEAD,
            '9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d'
        );

        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/leads/9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d',
            $url
        );
    }

    public function testBuildsAListLinkWhenNoIdentifierIsGiven(): void
    {
        $app = app(Apps::class);

        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/orders',
            new AdminLinkService()->build($app, AdminLinkSectionEnum::ORDER)
        );
    }

    /**
     * array_filter() drops falsy parts, so a slug of "0" used to vanish and the detail link silently
     * became a link to the list screen — the failure this whole module exists to prevent.
     */
    public function testAFalsyIdentifierStillProducesADetailLink(): void
    {
        $app = app(Apps::class);

        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/category/0',
            new AdminLinkService()->build($app, AdminLinkSectionEnum::CATEGORY, '0')
        );
    }

    public function testAListLinkUsesTheListPathWhenItDiffersFromTheDetailRoute(): void
    {
        $app = app(Apps::class);
        $service = new AdminLinkService();

        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/mapper/list',
            $service->build($app, AdminLinkSectionEnum::MAPPER)
        );

        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/mapper/7',
            $service->build($app, AdminLinkSectionEnum::MAPPER, 7)
        );
    }

    public function testAppendsAndEncodesTheQueryString(): void
    {
        $url = new AdminLinkService()->build(
            app(Apps::class),
            AdminLinkSectionEnum::AGENT,
            '9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d',
            ['tab' => 'nervous-system', 'viewName' => 'Agent Fleet']
        );

        $this->assertStringContainsString('?tab=nervous-system', $url);
        $this->assertStringContainsString('viewName=Agent+Fleet', $url);
    }

    public function testAppSettingWinsOverTheConfigFallback(): void
    {
        $app = app(Apps::class);
        $app->set(AppSettingsEnums::ADMIN_URL->getValue(), 'https://tenant.example.com/');

        try {
            $this->assertStringStartsWith(
                'https://tenant.example.com/projects/',
                new AdminLinkService()->build($app, AdminLinkSectionEnum::ORDER, 42)
            );
        } finally {
            $app->del(AppSettingsEnums::ADMIN_URL->getValue());
        }
    }

    public function testReturnsNullWhenNoHostIsConfigured(): void
    {
        config()->set('kanvas.app.frontend_url', null);

        $this->assertNull(
            new AdminLinkService()->build(app(Apps::class), AdminLinkSectionEnum::ORDER, 42)
        );
    }

    public function testConsoleHostsRefusePlatformLinksButAllowControlCenter(): void
    {
        config()->set('kanvas.app.frontend_url', 'https://console.kanvas.dev');
        $app = app(Apps::class);

        $this->assertNull(
            new AdminLinkService()->build($app, AdminLinkSectionEnum::LEAD, '9f1c-0000-4a5b-8c9d-1e2f')
        );

        $this->assertNotNull(
            new AdminLinkService()->build($app, AdminLinkSectionEnum::CONTROL_CENTER)
        );
    }

    public function testRejectsAnIdentifierThatDoesNotMatchTheRoute(): void
    {
        $this->expectException(ValidationException::class);

        new AdminLinkService()->build(app(Apps::class), AdminLinkSectionEnum::AGENT, 42);
    }

    public function testMetaCarriesTheCompanyGateAndSectionPermission(): void
    {
        $meta = new AdminLinkService()->meta(app(Apps::class), AdminLinkSectionEnum::LEAD, '9f1c-0000-4a5b-8c9d-1e2f');

        $this->assertTrue($meta->requiresCompany);
        $this->assertSame('CRM', $meta->sectionPermission);
        $this->assertNotNull($meta->url);

        $userMeta = new AdminLinkService()->meta(app(Apps::class), AdminLinkSectionEnum::USER, 1);

        $this->assertFalse($userMeta->requiresCompany);
        $this->assertSame('Ecosystem', $userMeta->sectionPermission);
    }
}
