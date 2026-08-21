<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\RespondIO;

use Baka\Support\Str;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\RespondIO\Actions\PushLeadAction;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Connectors\RespondIO\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class PushLeadActionTest extends TestCase
{
    public function testPushesLeadAsContactToRespondIO(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Company setting takes precedence over the app one, which other tests in the suite
        // also write — paratest runs them concurrently against the same app row.
        $company->set(ConfigurationEnum::BEARER_TOKEN->value, 'test-bearer-token');

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $expectedPhone = Str::toE164($people->getAllPhones()->first()->value);
        $expectedIdentifier = 'phone:' . $expectedPhone;
        $expectedContactId = 'contact_abc123';

        Http::fake([
            'api.respond.io/v2/contact/create_or_update/*' => Http::response(
                ['id' => $expectedContactId, 'phone' => $expectedPhone],
                200
            ),
            'api.respond.io/v2/contact/*/tag' => Http::response(['success' => true], 200),
            'api.respond.io/v2/contact/*/conversation/status' => Http::response(['success' => true], 200),
        ]);

        $response = new PushLeadAction($lead)->execute();

        $this->assertSame($expectedContactId, $response['id']);
        $this->assertSame($expectedContactId, $people->fresh()->get(CustomFieldEnum::RESPONDIO_CONTACT_ID->value));

        Http::assertSent(function (Request $request) use ($expectedIdentifier, $expectedPhone) {
            if (! str_contains($request->url(), 'create_or_update/' . $expectedIdentifier)) {
                return false;
            }

            $body = $request->data();

            return ($body['phone'] ?? null) === $expectedPhone
                && array_key_exists('firstName', $body)
                && array_key_exists('lastName', $body)
                && array_key_exists('email', $body);
        });
    }

    public function testThrowsWhenLeadHasNoPhoneOrEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Company setting takes precedence over the app one, which other tests in the suite
        // also write — paratest runs them concurrently against the same app row.
        $company->set(ConfigurationEnum::BEARER_TOKEN->value, 'test-bearer-token');

        // People created without ->withContacts() has no phones/emails
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        Http::fake();

        $this->expectExceptionMessage('Lead people record has no phone or email');

        new PushLeadAction($lead)->execute();

        Http::assertNothingSent();
    }
}
