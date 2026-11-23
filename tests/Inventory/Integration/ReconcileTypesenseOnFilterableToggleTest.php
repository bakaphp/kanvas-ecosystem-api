<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Jobs\ReconcileTypesenseAttributeSchemaJob;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReconcileTypesenseOnFilterableToggleTest extends TestCase
{
    public function testTogglingFilterableQueuesTheSchemaReconcile(): void
    {
        $attribute = $this->makeAttribute();

        Bus::fake([ReconcileTypesenseAttributeSchemaJob::class]);

        $attribute->is_filtrable = 1;
        $attribute->save();

        Bus::assertDispatched(
            ReconcileTypesenseAttributeSchemaJob::class,
            fn (ReconcileTypesenseAttributeSchemaJob $job) => $job->app->getId() === $attribute->apps_id
        );
    }

    public function testCreatingANonFilterableAttributeQueuesNothing(): void
    {
        Bus::fake([ReconcileTypesenseAttributeSchemaJob::class]);

        $this->makeAttribute();

        Bus::assertNotDispatched(ReconcileTypesenseAttributeSchemaJob::class);
    }

    public function testSavingWithoutTouchingFilterableQueuesNothing(): void
    {
        $attribute = $this->makeAttribute();

        Bus::fake([ReconcileTypesenseAttributeSchemaJob::class]);

        $attribute->is_visible = 0;
        $attribute->save();

        Bus::assertNotDispatched(ReconcileTypesenseAttributeSchemaJob::class);
    }

    private function makeAttribute(): Attributes
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $name = 'Filter-' . uniqid();

        return new CreateAttribute(
            new AttributesDto(
                company: $user->getCurrentCompany(),
                app: $app,
                user: $user,
                name: $name,
                slug: strtolower($name),
                attributeType: null,
                isVisible: true,
            ),
            $user
        )->execute();
    }
}
