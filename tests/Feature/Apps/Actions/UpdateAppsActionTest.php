<?php

declare(strict_types=1);

namespace Tests\Feature\Apps\Actions;

use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Actions\UpdateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class UpdateAppsActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     *
     * @return void
     */
    public function testCreateAppsAction(): void
    {
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
        //Create new AppInput
        $dtoData = AppInput::from($data);
        $user = auth()->user();

        $createApp = new CreateAppsAction($dtoData, $user);
        $app = $createApp->execute();

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

        //Create new AppInput
        $dtoData = AppInput::from($data);

        $updateApp = new UpdateAppsAction($dtoData, $user);

        $this->assertInstanceOf(
            Apps::class,
            $updateApp->execute($app->key->toString())
        );
    }
}
