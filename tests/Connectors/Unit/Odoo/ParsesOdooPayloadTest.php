<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Odoo;

use Kanvas\Connectors\Odoo\Actions\Concerns\ParsesOdooPayload;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Tests\TestCase;

final class ParsesOdooPayloadTest extends TestCase
{
    /**
     * Odoo answers `false` for every unset char/text field, so the parser is what keeps a bare
     * `false` out of a `?string` DTO property (which is a TypeError, not a null).
     */
    private function parser(array $payload): object
    {
        return new class ($payload) {
            use ParsesOdooPayload;

            public function __construct(protected array $payload)
            {
            }

            public function string(string $key): ?string
            {
                return $this->payloadString($key);
            }

            public function contacts(string $emailKey, string $phoneKey): array
            {
                return $this->contactsFromPayload($emailKey, $phoneKey);
            }

            public function id(mixed $value): ?string
            {
                return $this->relationId($value);
            }

            public function relation(mixed $value): ?string
            {
                return $this->relationName($value);
            }
        };
    }

    public function testUnsetFieldsComeBackAsNullNotFalse(): void
    {
        $parser = $this->parser(['description' => false, 'name' => '  Acme  ', 'blank' => '   ']);

        $this->assertNull($parser->string('description'));
        $this->assertNull($parser->string('missing'));
        $this->assertNull($parser->string('blank'));
        $this->assertSame('Acme', $parser->string('name'));
    }

    public function testContactsUseTheContactTypeEnumAndSkipUnsetValues(): void
    {
        $parser = $this->parser(['email_from' => 'sales@acme.test', 'phone' => false]);

        $this->assertSame(
            [['value' => 'sales@acme.test', 'contacts_types_id' => ContactTypeEnum::EMAIL->value, 'weight' => 0]],
            $parser->contacts('email_from', 'phone'),
        );
    }

    public function testContactsAreEmptyWhenNeitherFieldIsSet(): void
    {
        $parser = $this->parser(['email' => false, 'phone' => false]);

        $this->assertSame([], $parser->contacts('email', 'phone'));
    }

    public function testMany2OneFieldsAreSplitIntoIdAndDisplayName(): void
    {
        $parser = $this->parser([]);

        $this->assertSame('42', $parser->id([42, 'Acme Corp']));
        $this->assertSame('Acme Corp', $parser->relation([42, 'Acme Corp']));
        $this->assertNull($parser->id(false));
        $this->assertNull($parser->relation(false));
    }
}
