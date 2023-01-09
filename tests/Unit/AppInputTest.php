<?php
declare(strict_types=1);

namespace Tests\Unit;

use Kanvas\Apps\DataTransferObject\AppInput;
use Tests\TestCaseUnit;

final class AppInputTest extends TestCaseUnit
{
    /**
     * Test Create AppsPostData Dto.
     *
     * @return void
     */
    public function testCreateAppsPostDataDto() : void
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

        $this->assertInstanceOf(
            AppInput::class,
            AppInput::from($data)
        );
    }
}
