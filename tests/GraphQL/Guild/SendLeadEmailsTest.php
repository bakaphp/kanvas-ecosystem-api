<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Kanvas\Guild\Leads\Actions\SendLeadEmailsAction;
use Kanvas\Guild\Leads\Models\Lead;

class SendLeadEmailsTest extends TestCase
{
    public function testSendLeadEmails(): void
    {
        Notification::fake();
        $user = auth()->user();
        $title = fake()->title();

        $lead = Lead::factory()->create();

        $sendLeadEmailsAction = new SendLeadEmailsAction($lead, 'new-lead');
        $payload = [
            "title" => $title,
            "people" => [
                "contacts" => [
                    ["value" => "jesusant.guerrero@gmail.com", "weight" => 0, "contacts_types_id" => 1],
                    ["value" => "8292097833", "weight" => 0, "contacts_types_id" => 2]
                ],
                "lastname" => "Guerrero",
                "firstname" => "Jesus",
            ],
            "custom_fields" => [
                [
                    "data" => "218062",
                    "name" => "product_id"
                ],
                [
                    "data" => "7",
                    "name" => "share_left"
                ]
            ],
            "pipeline_stage_id" => 0
        ];

        $sendLeadEmailsAction->execute($payload, $user);
        Notification::assertCount(2);
    }
}
