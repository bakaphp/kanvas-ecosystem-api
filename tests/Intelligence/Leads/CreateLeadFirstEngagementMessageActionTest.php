<?php

declare(strict_types=1);

namespace Tests\Intelligence\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class CreateLeadFirstEngagementMessageActionTest extends TestCase
{
    public function testExecuteReturnsStructuredResponse(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Create the FirstMessagingAgent
        Agent::factory()->create([
            'name' => 'FirstMessagingAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'role' => [
                'background' => [
                    'You are an AI assistant helping to create engaging first messages for leads.',
                    'Your goal is to create personalized and compelling messages.',
                ],
                'steps' => [
                    'Generate a compelling title that captures attention.',
                    'Create an engaging message body that encourages response.',
                    'Use the lead information: {{$people["name"]}} from {{$company["name"]}} to personalize the message.',
                ],
            ],
        ]);

        // Create fake response
        $fakeStructuredData = [
            'title' => 'Welcome to Our Service!',
            'message' => 'Hi there! We noticed you\'re interested in our services. We\'d love to help you get started on your journey.',
        ];

        $fakeResponse = StructuredResponseFake::make()
            ->withText(json_encode($fakeStructuredData, JSON_THROW_ON_ERROR))
            ->withStructured($fakeStructuredData)
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(100, 50))
            ->withMeta(new Meta('fake-response-id', 'gemini-2.0-flash'));

        // Set up the fake
        Prism::fake([$fakeResponse]);

        // Execute the action
        $action = new CreateLeadFirstEngagementMessageAction($lead);
        $result = $action->execute();

        // Assert the structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertEquals('Welcome to Our Service!', $result['title']);
        $this->assertEquals('Hi there! We noticed you\'re interested in our services. We\'d love to help you get started on your journey.', $result['message']);
        $this->assertIsString($result['title']);
        $this->assertIsString($result['message']);
    }

    public function testExecuteThrowsExceptionWhenAgentRoleEmpty(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Create the agent with empty role
        Agent::factory()->create([
            'name' => 'FirstMessagingAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'role' => [],
        ]);

        // Execute the action and expect exception
        $action = new CreateLeadFirstEngagementMessageAction($lead);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent background or steps are empty');

        $action->execute();
    }
}
