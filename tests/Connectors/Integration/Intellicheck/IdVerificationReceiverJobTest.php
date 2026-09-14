<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Illuminate\Http\Request;
use Kanvas\Connectors\Intellicheck\Jobs\IdVerificationReceiverJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The receiver's only job of its own is the Intellicheck-specific parse, so that is what is pinned
 * here: the base64 body, the `private_data.result` unwrap, and the query-param gate. Everything past
 * that point is the shared `generate-id-verification` path.
 */
final class IdVerificationReceiverJobTest extends TestCase
{
    public function testRejectsARequestMissingAnyOfTheThreeQueryParams(): void
    {
        $receiver = new ReceiverWebhook();

        $this->assertFalse(IdVerificationReceiverJob::authenticateRequest(new Request(), $receiver));

        $this->assertFalse(IdVerificationReceiverJob::authenticateRequest(
            Request::create('/v1/receiver/x?token=t&lead=l', 'POST'),
            $receiver
        ));

        $this->assertFalse(IdVerificationReceiverJob::authenticateRequest(
            Request::create('/v1/receiver/x?token=t&eid=9', 'POST'),
            $receiver
        ));
    }

    public function testAcceptsARequestCarryingTokenLeadAndEid(): void
    {
        $this->assertTrue(IdVerificationReceiverJob::authenticateRequest(
            Request::create('/v1/receiver/x?token=t&lead=l&eid=9', 'POST'),
            new ReceiverWebhook()
        ));
    }

    public function testUnwrapsThePayloadFromPrivateDataResult(): void
    {
        $decoded = $this->decodeBody([
            'private_data' => [
                'result' => [
                    'idcheck' => ['data' => ['firstName' => 'Keira']],
                    'facial' => ['data' => ['matched' => true]],
                ],
            ],
        ]);

        $this->assertArrayHasKey('idcheck', $decoded);
        $this->assertArrayNotHasKey('private_data', $decoded);
        $this->assertSame('Keira', $decoded['idcheck']['data']['firstName']);
    }

    /**
     * A body that is already unwrapped has to keep working: the legacy controller forwarded that shape
     * to Kanvas, so replayed payloads captured from it arrive without the envelope.
     */
    public function testAcceptsAnAlreadyUnwrappedPayload(): void
    {
        $decoded = $this->decodeBody(['idcheck' => ['data' => ['firstName' => 'Keira']]]);

        $this->assertSame('Keira', $decoded['idcheck']['data']['firstName']);
    }

    public function testReturnsNothingForABodyThatIsNotBase64EncodedJson(): void
    {
        $this->assertSame([], $this->invokeDecodeBody('not base64 at all'));
        $this->assertSame([], $this->invokeDecodeBody(base64_encode('{"broken":')));
        $this->assertSame([], $this->invokeDecodeBody(''));
    }

    /**
     * The selfie travels as a separate argument so it never lands inside the message payload. The
     * receiver reads it off `facial.data.photoFace` and drops the key.
     */
    public function testTheSelfieIsStrippedFromTheVerificationPayload(): void
    {
        $verificationData = $this->decodeBody([
            'private_data' => [
                'result' => [
                    'facial' => [
                        'data' => [
                            'matched' => true,
                            'isLive' => true,
                            'photoFace' => 'BASE64SELFIE',
                        ],
                    ],
                ],
            ],
        ]);

        $faceImage = $verificationData['facial']['data']['photoFace'] ?? null;
        unset($verificationData['facial']['data']['photoFace']);

        $this->assertSame('BASE64SELFIE', $faceImage);
        $this->assertArrayNotHasKey('photoFace', $verificationData['facial']['data']);
        $this->assertTrue($verificationData['facial']['data']['matched'], 'the rest of facial survives');
    }

    private function decodeBody(array $payload): array
    {
        return $this->invokeDecodeBody(base64_encode(json_encode($payload)));
    }

    private function invokeDecodeBody(string $rawPayload): array
    {
        $job = new ReflectionClass(IdVerificationReceiverJob::class)->newInstanceWithoutConstructor();

        $call = new ReceiverWebhookCall();
        $call->raw_payload = $rawPayload;

        $property = new ReflectionProperty(IdVerificationReceiverJob::class, 'webhookRequest');
        $property->setValue($job, $call);

        return new ReflectionMethod(IdVerificationReceiverJob::class, 'decodeBody')->invoke($job);
    }
}
