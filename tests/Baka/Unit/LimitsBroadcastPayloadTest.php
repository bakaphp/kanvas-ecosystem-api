<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Traits\LimitsBroadcastPayload;
use Tests\TestCaseUnit;

final class LimitsBroadcastPayloadTest extends TestCaseUnit
{
    private function subject(): object
    {
        return new class () {
            use LimitsBroadcastPayload;

            /**
             * @param array<string, mixed> $fields
             *
             * @return array<string, mixed>
             */
            public function limit(array $fields, int $maxBytes): array
            {
                return $this->limitBroadcastPayloadSet($fields, $maxBytes);
            }
        };
    }

    public function testKeepsBothFieldsWhenJointlyUnderBudget(): void
    {
        $fields = ['message' => 'hello', 'response' => 'world'];

        $this->assertSame($fields, $this->subject()->limit($fields, 8192));
    }

    public function testNullsTheLargestFieldWhenTwoSmallFieldsJointlyExceedBudget(): void
    {
        // Each field is individually under the cap, but together they blow it —
        // the per-field cap missed this, which is the bug behind the Pusher 10240 error.
        $message = str_repeat('m', 4000);
        $response = str_repeat('r', 6000);

        $result = $this->subject()->limit(
            ['message' => $message, 'response' => $response],
            8192,
        );

        $this->assertNull($result['response'], 'largest field is nulled first');
        $this->assertSame($message, $result['message'], 'smaller field is retained');
    }

    public function testNullsBothWhenEachAloneExceedsBudget(): void
    {
        $result = $this->subject()->limit(
            ['message' => str_repeat('m', 9000), 'response' => str_repeat('r', 9000)],
            8192,
        );

        $this->assertNull($result['message']);
        $this->assertNull($result['response']);
    }
}
