<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Kanvas\Scribe\PaymentReceipts\Enums\ConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Scribe\ScribeTestCase;

#[Group('serial')]
class PaymentReceiptSettingsGraphQLTest extends ScribeTestCase
{
    private const string CONFIGURE = '
        mutation ($input: ScribePaymentReceiptSettingsInput!) {
            configureScribePaymentReceipt(input: $input) { received_by signature_url seal_url }
        }';

    private const string CURRENT = 'query { scribePaymentReceiptSettings { received_by signature_url seal_url } }';

    private mixed $originalReceivedBy = null;

    protected function afterScribeSetUp(): void
    {
        // Company settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalReceivedBy = $this->company->get(ConfigurationEnum::RECEIVED_BY->value);
    }

    protected function tearDown(): void
    {
        $this->originalReceivedBy === null
            ? $this->company->del(ConfigurationEnum::RECEIVED_BY->value)
            : $this->company->set(ConfigurationEnum::RECEIVED_BY->value, $this->originalReceivedBy);

        parent::tearDown();
    }

    public function test_the_name_is_saved_trimmed_and_read_back(): void
    {
        $this->graphQL(self::CONFIGURE, ['input' => ['received_by' => '  Test Receiver ']])
            ->assertJsonPath('data.configureScribePaymentReceipt.received_by', 'Test Receiver');

        $this->graphQL(self::CURRENT)
            ->assertJsonPath('data.scribePaymentReceiptSettings.received_by', 'Test Receiver');
    }

    public function test_signature_and_seal_are_stored_under_the_names_the_receipt_reads(): void
    {
        $this->configureWithImages(signature: 'signature.png', seal: 'seal.png')
            ->assertSuccessful()
            ->assertJsonPath('data.configureScribePaymentReceipt.signature_url', fn (?string $url): bool => $url !== null)
            ->assertJsonPath('data.configureScribePaymentReceipt.seal_url', fn (?string $url): bool => $url !== null);

        $this->assertNotNull($this->company->getFileByName(ConfigurationEnum::SIGNATURE_FILE->value));
        $this->assertNotNull($this->company->getFileByName(ConfigurationEnum::SEAL_FILE->value));
    }

    public function test_what_is_left_out_stays_and_an_empty_name_clears_it(): void
    {
        $this->graphQL(self::CONFIGURE, ['input' => ['received_by' => 'Test Receiver']])->assertSuccessful();
        $this->configureWithImages(signature: 'signature.png')->assertSuccessful();

        $this->graphQL(self::CURRENT)
            ->assertJsonPath('data.scribePaymentReceiptSettings.received_by', 'Test Receiver')
            ->assertJsonPath('data.scribePaymentReceiptSettings.signature_url', fn (?string $url): bool => $url !== null);

        $this->graphQL(self::CONFIGURE, ['input' => ['received_by' => '']])
            ->assertJsonPath('data.configureScribePaymentReceipt.received_by', null);
    }

    public function test_a_file_that_is_not_an_image_is_refused_before_anything_is_saved(): void
    {
        $this->graphQL(self::CONFIGURE, ['input' => ['received_by' => 'Test Receiver']])->assertSuccessful();

        $this->configureWithImages(signature: 'signature.exe', receivedBy: 'Other Receiver')
            ->assertGraphQLErrorMessage('Invalid file format exe');

        $this->graphQL(self::CURRENT)
            ->assertJsonPath('data.scribePaymentReceiptSettings.received_by', 'Test Receiver');
    }

    private function configureWithImages(
        ?string $signature = null,
        ?string $seal = null,
        ?string $receivedBy = null,
    ): TestResponse {
        $files = [];
        $map = [];

        foreach (['signature' => $signature, 'seal' => $seal] as $field => $name) {
            if ($name === null) {
                continue;
            }

            $map[(string) count($files)] = ["variables.input.{$field}"];
            $files[(string) count($files)] = UploadedFile::fake()->create($name, 10);
        }

        return $this->multipartGraphQL(
            [
                'query' => self::CONFIGURE,
                'variables' => [
                    'input' => array_filter(['received_by' => $receivedBy], static fn (?string $name): bool => $name !== null)
                        + array_fill_keys(array_keys(array_filter(compact('signature', 'seal'))), null),
                ],
            ],
            $map,
            $files
        );
    }
}
