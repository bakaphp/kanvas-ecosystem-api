<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Tests\TestCase;

/**
 * `AddressInput.type` is fed straight into `AddressTypeEnum::from()`, so a case the GraphQL enum omits is a
 * case the API silently rejects. Lighthouse 6 cannot bind a PHP enum class, so the two lists are maintained by
 * hand and drift the moment someone adds a case to one and not the other — which is exactly what happened
 * (the schema shipped without PreviousHome / PreviousEmployer, both of which are in live use).
 */
final class AddressTypeEnumSchemaTest extends TestCase
{
    private const string SCHEMA = __DIR__ . '/../../../graphql/schemas/Guild/contact.graphql';

    public function testEveryPhpCaseIsExposedInTheGraphqlEnum(): void
    {
        $schema = (string) file_get_contents(self::SCHEMA);

        preg_match('/enum AddressTypeEnum \{(.*?)\}/s', $schema, $block);
        $this->assertNotEmpty($block, 'AddressTypeEnum is missing from contact.graphql.');

        preg_match_all('/@enum\(value: "([^"]+)"\)/', $block[1], $matches);
        $inSchema = $matches[1];

        $inPhp = array_map(fn (AddressTypeEnum $case): string => $case->value, AddressTypeEnum::cases());

        sort($inSchema);
        sort($inPhp);

        $this->assertSame(
            $inPhp,
            $inSchema,
            'graphql/schemas/Guild/contact.graphql and Guild\Customers\Enums\AddressTypeEnum have drifted.'
        );
    }
}
