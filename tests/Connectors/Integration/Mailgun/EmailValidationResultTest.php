<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Kanvas\Connectors\Mailgun\DataTransferObject\EmailValidationResult;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Tests\TestCase;

final class EmailValidationResultTest extends TestCase
{
    public function test_maps_mailgun_results_to_validation_status(): void
    {
        $this->assertSame(ContactValidationStatusEnum::VALID, $this->resultFor('deliverable')->toValidationStatus());
        $this->assertSame(ContactValidationStatusEnum::HARD_BOUNCE, $this->resultFor('undeliverable')->toValidationStatus());
        $this->assertSame(ContactValidationStatusEnum::INVALID, $this->resultFor('do_not_send')->toValidationStatus());

        // Inconclusive verdicts never flag an address.
        $this->assertNull($this->resultFor('catch_all')->toValidationStatus());
        $this->assertNull($this->resultFor('unknown')->toValidationStatus());
    }

    public function test_parses_the_api_response_shape(): void
    {
        $result = EmailValidationResult::fromApiResponse([
            'address' => 'a.quinones@bancentral.gob.do',
            'result' => 'undeliverable',
            'risk' => 'high',
            'is_disposable_address' => false,
            'is_role_address' => true,
            'reason' => ['mailbox_does_not_exist'],
        ]);

        $this->assertSame('a.quinones@bancentral.gob.do', $result->address);
        $this->assertSame('undeliverable', $result->result);
        $this->assertSame('high', $result->risk);
        $this->assertTrue($result->isRoleAddress);
        $this->assertSame(['mailbox_does_not_exist'], $result->reasons);
    }

    public function test_defaults_to_unknown_on_a_sparse_response(): void
    {
        $result = EmailValidationResult::fromApiResponse([]);

        $this->assertSame('unknown', $result->result);
        $this->assertNull($result->toValidationStatus());
    }

    private function resultFor(string $result): EmailValidationResult
    {
        return new EmailValidationResult('a@b.test', $result, 'low', false, false, []);
    }
}
