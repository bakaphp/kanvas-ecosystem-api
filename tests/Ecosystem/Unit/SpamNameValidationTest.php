<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Unit;

use Illuminate\Validation\ValidationException;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Stubs\Auth\InMemorySettingsApp;
use Tests\Stubs\Auth\NameValidatingUserAction;
use Tests\TestCaseUnit;

final class SpamNameValidationTest extends TestCaseUnit
{
    /**
     * Short capitalized surnames are 40%+ uppercase by construction. The
     * original ratio check rejected every one of them — a real customer
     * ("David So") could not register.
     *
     * @return list<array{string, string}>
     */
    public static function legitimateNameProvider(): array
    {
        return [
            ['David', 'So'],
            ['Wei', 'Li'],
            ['Minh', 'Ng'],
            ['Jian', 'Wu'],
            ['Grace', 'Oh'],
            ['Ana', 'Yu'],
            ['John', 'Smith'],
            ['Maria', 'Rodriguez-Perez'],
            ['Sean', "O'Brien"],
            ['Ronald', 'McDonald'],
            ['Ana', 'MacArthur'],
            ['JOHN', 'SMITH'],
            ['jose', 'perez'],
        ];
    }

    /**
     * Randomized casing is the signature the filter exists to catch.
     *
     * @return list<array{string, string}>
     */
    public static function spamNameProvider(): array
    {
        return [
            ['aXbTqZmR', 'kLpWnBvC'],
            ['David', 'aXbTqZmR'],
            ['mAcHdH', 'Smith'],
            ['XkCdQw', 'Perez'],
        ];
    }

    #[DataProvider('legitimateNameProvider')]
    public function testLegitimateNamesAreAccepted(string $firstname, string $lastname): void
    {
        $this->expectNotToPerformAssertions();

        $this->validateNames($firstname, $lastname);
    }

    #[DataProvider('spamNameProvider')]
    public function testRandomizedCasingNamesAreRejected(string $firstname, string $lastname): void
    {
        $this->expectException(ValidationException::class);

        $this->validateNames($firstname, $lastname);
    }

    /**
     * The original code built a validator that was never failed, so Lighthouse
     * serialized `extensions.validation` as an empty array and the client got a
     * bare "The given data was invalid." with no field and no reason.
     */
    public function testRejectionCarriesTheFieldAndReason(): void
    {
        try {
            $this->validateNames('David', 'aXbTqZmR');

            $this->fail('Expected a ValidationException for a randomized-casing last name.');
        } catch (ValidationException $exception) {
            $errors = $exception->validator->errors()->messages();

            $this->assertNotEmpty($errors, 'The validation payload reached the client empty.');
            $this->assertArrayHasKey('lastname', $errors);
            $this->assertArrayNotHasKey('firstname', $errors);
            $this->assertSame(
                ['Registration information appears to be invalid.'],
                $errors['lastname']
            );
        }
    }

    public function testIdenticalFirstAndLastNamesAreRejectedWithBothFields(): void
    {
        try {
            $this->validateNames('Bogus', 'Bogus');

            $this->fail('Expected a ValidationException when both names match.');
        } catch (ValidationException $exception) {
            $errors = $exception->validator->errors()->messages();

            $this->assertArrayHasKey('firstname', $errors);
            $this->assertArrayHasKey('lastname', $errors);
        }
    }

    private function validateNames(string $firstname, string $lastname): void
    {
        $data = new RegisterInput(
            firstname: $firstname,
            lastname: $lastname,
            displayname: 'test-user',
            email: 'test@example.com',
            password: 'irrelevant-for-name-validation'
        );

        new NameValidatingUserAction($data, InMemorySettingsApp::withSettings())->runNameValidation();
    }
}
