<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Baka\Contracts\HashTableInterface;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class SecretSettingsTest extends TestCase
{
    private const SECRET_VALUE = 'graphql-secret-value';

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = 'TEST_SECRET_' . Str::random(12);

        // Admin, so a `public: true` that gets ignored is ignored because the value is secret, not for lack of rights.
        app(Apps::class)->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);
    }

    protected function tearDown(): void
    {
        $user = auth()->user();
        app(Apps::class)->del($this->key);
        $user->getCurrentCompany()->del($this->key);
        $user->del($this->key);

        parent::tearDown();
    }

    public function testSetAppSettingWithSecretStoresCiphertextAndIsNeverPublic(): void
    {
        $app = app(Apps::class);

        $this->setSetting('setAppSetting', ['public' => true])
            ->assertJson(['data' => ['setAppSetting' => true]]);

        $this->assertTrue($app->isSecret($this->key));
        $this->assertSame(self::SECRET_VALUE, $app->get($this->key));
        $this->assertArrayNotHasKey($this->key, $app->getAllSettings(onlyPublicSettings: true, fromRedis: false));
    }

    public function testAdminAppQueriesMaskTheSecret(): void
    {
        $this->setSetting('setAppSetting');

        $list = $this->adminQuery('{ adminAppSettings { key value public } }')->json('data.adminAppSettings');
        $entry = collect($list)->firstWhere('key', $this->key);

        $this->assertNotNull($entry);
        $this->assertNull($entry['value']);
        $this->assertFalse($entry['public']);

        $this->adminQuery('{ adminAppSetting(key: "' . $this->key . '") }')
            ->assertJson(['data' => ['adminAppSetting' => null]])
            ->assertDontSee(self::SECRET_VALUE)
            ->assertDontSee(HashTableInterface::SECRET_PREFIX);
    }

    public function testCompanySecretIsEncryptedAndMaskedInBothQueries(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $this->setSetting('setCompanySetting', ['entity_uuid' => $company->uuid])
            ->assertJson(['data' => ['setCompanySetting' => true]]);

        $this->assertTrue($company->isSecret($this->key));
        $this->assertSame(self::SECRET_VALUE, $company->get($this->key));

        $list = $this->adminQuery('{ adminCompanySettings(entity_uuid: "' . $company->uuid . '") { key value } }')
            ->assertDontSee(self::SECRET_VALUE)
            ->json('data.adminCompanySettings');
        $this->assertNull(collect($list)->firstWhere('key', $this->key)['value']);

        $this->adminQuery('{ adminCompanySetting(entity_uuid: "' . $company->uuid . '", key: "' . $this->key . '") }')
            ->assertJson(['data' => ['adminCompanySetting' => null]]);
    }

    public function testUserSecretIsEncryptedAndMasked(): void
    {
        $user = auth()->user();

        $this->setSetting('setUserSetting', ['entity_uuid' => $user->uuid])
            ->assertJson(['data' => ['setUserSetting' => true]]);

        $this->assertTrue($user->isSecret($this->key));
        $this->assertSame(self::SECRET_VALUE, $user->get($this->key));

        $list = $this->adminQuery('{ userSettings(entity_uuid: "' . $user->uuid . '") { key value } }')
            ->assertDontSee(self::SECRET_VALUE)
            ->json('data.userSettings');
        $this->assertNull(collect($list)->firstWhere('key', $this->key)['value']);
    }

    public function testWithoutSecretFlagTheValueIsStoredAndReturnedInPlain(): void
    {
        $this->setSetting('setAppSetting', ['secret' => false]);

        $this->assertFalse(app(Apps::class)->isSecret($this->key));
        $this->adminQuery('{ adminAppSetting(key: "' . $this->key . '") }')
            ->assertJson(['data' => ['adminAppSetting' => self::SECRET_VALUE]]);
    }

    private function setSetting(string $mutation, array $overrides = []): mixed
    {
        return $this->graphQL(
            /** @lang GraphQL */
            'mutation($input: ModuleConfigInput!) { ' . $mutation . '(input: $input) }',
            [
                'input' => $overrides + [
                    'key' => $this->key,
                    'value' => self::SECRET_VALUE,
                    'secret' => true,
                ],
            ],
            [],
            $this->appKeyHeader()
        );
    }

    private function adminQuery(string $query): mixed
    {
        return $this->graphQL($query, [], [], $this->appKeyHeader())->assertSuccessful();
    }

    private function appKeyHeader(): array
    {
        return [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => app(Apps::class)->keys()->first()->client_secret_id];
    }
}
