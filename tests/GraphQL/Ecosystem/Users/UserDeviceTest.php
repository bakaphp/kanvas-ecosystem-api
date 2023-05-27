<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Kanvas\Users\Models\Sources;
use Tests\TestCase;

class UserDeviceTest extends TestCase
{
    public function testLinkUserDevice()
    {
        $source = Sources::firstOrFail();
        
        $this->graphQL(/** @lang GraphQL */ '
            mutation linkDevice($data: DeviceInput!) {
                linkDevice(data: $data) 
            }
        ', [
            'data' => [
                'source_site' => $source->title,
                'device_id' => '123456789',
                'source_username' => 'kanvasniche',
            ],
        ])->assertJson([
            'data' => [
                'linkDevice' => true,
            ],
        ]);
    
    }
    public function testUnLinkUserDevice()
    {
        $source = Sources::firstOrFail();
        $this->graphQL(/** @lang GraphQL */ '
            mutation unLinkDevice($data: DeviceInput!) {
                unLinkDevice(data: $data) 
            }
        ', [
            'data' => [
                'source_site' => $source->title,
                'device_id' => '123456789',
                'source_username' => 'kanvasniche',
            ],
        ])->assertJson([
            'data' => [
                'linkDevice' => true,
            ],
        ]);
    }
}
