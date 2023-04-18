<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Tests\TestCase;

class AppsSettingsTest extends TestCase
{
    public function testSmtp()
    {
        $input = [
            'host' => 'mailhog',
            'port' => '1025',
            'username' => 'null',
            'password' => 'null',
            'encryption' => 'null',
            'fromEmail' => 'hello@example.com',
            'fromName' => 'Kanvas',
        ];
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation saveAppSmtpSettings(
                $input: appSmtpInput!
            ){
                saveAppSmtpSettings(
                    input: $input
                )
            }',
            [
                'input' => $input,
            ],
        );
        $response->assertJson([
            'data' => [
                'saveAppSmtpSettings' => true,
            ],
        ]);
    }
}
