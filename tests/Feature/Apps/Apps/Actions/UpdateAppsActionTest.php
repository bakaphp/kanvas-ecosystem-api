<?php
declare(strict_types=1);

namespace Tests\Feature\Apps\Apps\Actions;

use Kanvas\Apps\Actions\UpdateAppsAction;
use Kanvas\Apps\DataTransferObject\AppsPutData;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class UpdateAppsActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     *
     * @return void
     */
    public function testCreateAppsAction() : void
    {
        //$app = Apps::factory()->create();
        $app = Apps::where('id', '>', '0')->first();

        $data = [
            'url' => 'example.com',
            'is_actived' => '1',
            'ecosystem_auth' => '1',
            'payments_active' => '1',
            'is_public' => '1',
            'domain_based' => '1',
            'name' => 'CRM app 2',
            'description' => 'Kanvas Application',
            'domain' => 'example.com',
        ];

        //Create new AppsPostData
        $dtoData = AppsPutData::fromArray($data);

        $updateApp = new UpdateAppsAction($dtoData);

        $this->assertInstanceOf(
            Apps::class,
            $updateApp->execute($app->id)
        );
    }
}
