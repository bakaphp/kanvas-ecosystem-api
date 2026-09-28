<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration;

use Kanvas\Apps\Models\Apps;
use Tests\Stubs\AdminLinks\NumericRecordStub;
use Tests\Stubs\AdminLinks\UuidRecordStub;
use Tests\TestCase;

final class HasAdminLinkTest extends TestCase
{
    private const HOST = 'https://admin.kanvas.dev';
    private const UUID = '9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kanvas.app.frontend_url', self::HOST);
    }

    public function testResolvesTheUuidForRoutesThatAcceptEither(): void
    {
        $app = app(Apps::class);
        $record = $this->stub(new UuidRecordStub(), ['id' => 7, 'uuid' => self::UUID], $app);

        $this->assertSame(self::UUID, $record->adminLinkIdentifier());
        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/leads/' . self::UUID,
            $record->adminUrl()
        );
    }

    public function testResolvesTheNumericIdForNumericRoutes(): void
    {
        $app = app(Apps::class);
        $record = $this->stub(new NumericRecordStub(), ['id' => 42], $app);

        $this->assertSame(42, $record->adminLinkIdentifier());
        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/orders/42',
            $record->adminUrl()
        );
    }

    public function testPassesQueryStateThrough(): void
    {
        $record = $this->stub(new UuidRecordStub(), ['id' => 7, 'uuid' => self::UUID], app(Apps::class));

        $this->assertStringEndsWith('?tab=history', $record->adminUrl(['tab' => 'history']));
    }

    public function testReturnsNullWhenTheRecordHasNoUuidForAUuidRoute(): void
    {
        $record = $this->stub(new UuidRecordStub(), ['id' => 7], app(Apps::class));

        $this->assertNull($record->adminLinkIdentifier());
        $this->assertNull($record->adminUrl());
    }

    public function testReturnsNullUrlWhenTheRecordHasNoApp(): void
    {
        $record = new UuidRecordStub();
        $record->forceFill(['id' => 7, 'uuid' => self::UUID]);
        $record->setRelation('app', null);

        $this->assertNull($record->adminUrl());
    }

    public function testMetaIsStillDescriptiveWhenTheUrlCannotBeBuilt(): void
    {
        config()->set('kanvas.app.frontend_url', null);
        $record = $this->stub(new UuidRecordStub(), ['id' => 7, 'uuid' => self::UUID], app(Apps::class));

        $meta = $record->adminLinkMeta();

        $this->assertNull($meta->url);
        $this->assertTrue($meta->requiresCompany);
        $this->assertSame('CRM', $meta->sectionPermission);
    }

    private function stub(UuidRecordStub|NumericRecordStub $record, array $attributes, Apps $app): UuidRecordStub|NumericRecordStub
    {
        $record->forceFill($attributes);
        $record->setRelation('app', $app);

        return $record;
    }
}
