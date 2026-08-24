<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\RespondIO;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    public function testThrowsValidationExceptionWhenTokenIsStoredAsFalsyNumber(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        // Settings values round-trip through json_decode, so a token written as false/''
        // reads back as int 0 — this used to TypeError on Client::$bearerToken.
        $company->set(ConfigurationEnum::BEARER_TOKEN->value, 0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Respond.io bearer token is not set.');

        new Client($app, $company);
    }

    public function testAcceptsNumericTokenDecodedAsInt(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $company->set(ConfigurationEnum::BEARER_TOKEN->value, '123456789');

        Http::fake([
            'api.respond.io/v2/contact/*' => Http::response(['id' => 'contact_1'], 200),
        ]);

        new Client($app, $company)->getContact('id:1');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer 123456789'));
    }
}
