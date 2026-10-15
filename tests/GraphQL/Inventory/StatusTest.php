<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusData;
use Kanvas\Inventory\Status\Models\Status;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use Tests\Traits\AssertsIsDefaultOrdering;

class StatusTest extends TestCase
{
    use AssertsIsDefaultOrdering;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testTypesenseSchemaIdIsString(): void
    {
        $schema = new Status()->typesenseCollectionSchema();
        $idField = collect($schema['fields'])->firstWhere('name', 'id');
        $this->assertNotNull($idField);
        $this->assertSame('string', $idField['type'], 'Typesense requires the document id field to be a string');
    }

    public function testStatusOrderByIsDefault(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $suffix = Str::random(12);

        Bouncer::scope()->to(RolesEnums::getScope($app, global: true));
        Bouncer::assign('Admins')->to($user);
        Bouncer::allow('Admins')->to('view', Status::class);
        Bouncer::allow('Admins')->to('view-module-inventory');

        $createStatus = fn (string $name, bool $isDefault): int => new CreateStatusAction(
            new StatusData(
                app: $app,
                company: $company,
                user: $user,
                name: $name . ' ' . $suffix,
                is_default: $isDefault,
            ),
            $user
        )->execute()->getId();

        $defaultId = $createStatus('Default Status', true);
        $nonDefaultId = $createStatus('Plain Status', false);

        $query = '
            query($ids: Mixed!, $order: SortOrder!) {
                status(
                    where: {column: ID, operator: IN, value: $ids}
                    orderBy: [{column: IS_DEFAULT, order: $order}]
                ) {
                    data { id is_default }
                }
            }
        ';

        $this->assertOrdersByIsDefault(
            $query,
            'data.status.data',
            $defaultId,
            $nonDefaultId
        );
    }
}
