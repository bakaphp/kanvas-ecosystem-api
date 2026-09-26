<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Illuminate\Support\Facades\Cache;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Connectors\Intellicheck\Activities\GenerateIdVerificationFromMessageActivity;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use ReflectionClass;
use ReflectionMethod;
use Tests\Connectors\Integration\Intellicheck\Concerns\BuildsIdVerificationLead;
use Tests\TestCase;

/**
 * These pin what the message entry derives on its own: one message resolves exactly one engagement,
 * one person and one payload. The co-buyer case is covered by the lead-rooted activities too when the
 * caller sends the participant id — see the activity docblock for why this one still exists.
 */
final class GenerateIdVerificationFromMessageActivityTest extends TestCase
{
    use BuildsIdVerificationLead;

    public function testThePayloadIsReadFromTheMessagesScanEnvelope(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $this->writeScanPayload($engagement, 'Paul', 'Fitzpatrick');

        $resolved = $this->invokePayloadFromMessage($engagement->message);

        $this->assertSame('Paul', $resolved['idcheck']['data']['firstName']);
        $this->assertSame('Fitzpatrick', $resolved['idcheck']['data']['lastName']);
    }

    /**
     * The receiver unwraps `private_data.result` before firing, so a message written from that
     * envelope has one level less of nesting than the bot's own postback.
     */
    public function testTheUnwrappedEnvelopeIsAcceptedToo(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $message = $engagement->message;
        $message->message = [
            'verb' => ConfigurationEnum::ID_VERIFICATION->value,
            'private_data' => ['result' => ['idcheck' => ['data' => ['firstName' => 'Shelly']]]],
        ];
        $message->saveOrFail();

        $this->assertSame(
            'Shelly',
            $this->invokePayloadFromMessage($message->refresh())['idcheck']['data']['firstName']
        );
    }

    /**
     * A selfie is megabytes of base64 and `wkhtmltopdf` would try to inline it into the report.
     */
    public function testTheSelfieIsStrippedFromThePayload(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $message = $engagement->message;
        $message->message = [
            'data' => ['form' => ['private_data' => ['result' => [
                'idcheck' => ['data' => ['firstName' => 'Paul']],
                'facial' => ['data' => ['matched' => true, 'photoFace' => 'BASE64_SELFIE']],
            ]]]],
        ];
        $message->saveOrFail();

        $resolved = $this->invokePayloadFromMessage($message->refresh());

        $this->assertArrayNotHasKey('photoFace', $resolved['facial']['data']);
        $this->assertTrue($resolved['facial']['data']['matched'], 'the rest of the block must survive');
    }

    /**
     * The payload is external JSON, so `facial` is not guaranteed to be an array — and unsetting
     * through a scalar is a fatal `Error`, which `executeIntegration` would then report to Sentry.
     */
    public function testAScalarFacialBlockDoesNotBlowUpTheStrip(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $message = $engagement->message;
        $message->message = [
            'data' => ['form' => ['private_data' => ['result' => [
                'idcheck' => ['data' => ['firstName' => 'Paul']],
                'facial' => 'not-an-array',
            ]]]],
        ];
        $message->saveOrFail();

        $resolved = $this->invokePayloadFromMessage($message->refresh());

        $this->assertSame('not-an-array', $resolved['facial']);
        $this->assertSame('Paul', $resolved['idcheck']['data']['firstName']);
    }

    public function testAMessageWithoutAScanPayloadResolvesToNothing(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $message = $engagement->message;
        $message->message = ['verb' => 'note', 'text' => 'just a comment'];
        $message->saveOrFail();

        $this->assertSame([], $this->invokePayloadFromMessage($message->refresh()));
    }

    /**
     * The whole point of the message entry: the person comes from the engagement the message owns,
     * never from the lead — so a co-buyer's scan cannot resolve back to the main buyer.
     */
    public function testACoBuyersMessageResolvesTheCoBuyerNotTheLeadsPerson(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePerson($lead);

        $mainEngagement = $this->createEngagement($lead, $lead->people);
        $coBuyerEngagement = $this->createEngagement($lead, $coBuyer);

        $this->assertNotNull($mainEngagement);
        $this->assertNotNull($coBuyerEngagement);
        $this->assertNotSame($mainEngagement->getId(), $coBuyerEngagement->getId());

        $resolved = $coBuyerEngagement->message->engagement;

        $this->assertNotNull($resolved);
        $this->assertSame($coBuyerEngagement->getId(), $resolved->getId());
        $this->assertSame($coBuyer->getId(), $resolved->people_id);
        $this->assertNotSame($lead->people_id, $resolved->people_id);
    }

    /**
     * 3 of the 40 most recent scan messages in a production snapshot had no engagement row, so this
     * is a guard, not an assumption.
     */
    public function testAMessageWithNoEngagementIsNotAssumedToHaveOne(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $orphan = $engagement->message->replicate();
        $orphan->uuid = null;
        $orphan->saveOrFail();

        $this->assertNull($orphan->refresh()->engagement);
    }

    /**
     * The engagement the caller holds has to reach the report step untouched — the moment it falls
     * back to `resolveEngagement()` we are back to deriving it from the lead.
     */
    public function testThePassedEngagementReachesTheReportStep(): void
    {
        $lead = $this->makeLead();
        $coBuyer = $this->makePerson($lead);

        $engagement = $this->createEngagement($lead, $coBuyer);
        $this->assertNotNull($engagement);

        $action = $this->capturingAction($coBuyer, $lead);

        $action->execute(
            verificationData: $this->ocrOnlyPayload(),
            reuseExistingEngagement: true,
            engagement: $engagement,
            sendEmail: false,
        );

        $this->assertSame($engagement->getId(), $action->capturedEngagement?->getId());
    }

    /**
     * A regeneration must not consume the 3-minute window: a real scan arriving right after would
     * then silently get no report, no PDF and no engagement at all.
     */
    public function testSkippingTheEmailLeavesTheDedupWindowOpen(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $cacheKey = 'intellicheck_report_' . $lead->getId() . '_' . $lead->people->getId();
        Cache::forget($cacheKey);

        $this->capturingAction($lead->people, $lead)->execute(
            verificationData: $this->ocrOnlyPayload(),
            reuseExistingEngagement: true,
            engagement: $engagement,
            sendEmail: false,
        );

        $this->assertFalse(Cache::has($cacheKey), 'a regeneration must leave the window open');
    }

    public function testTheDefaultStillClaimsTheDedupWindow(): void
    {
        $lead = $this->makeLead();
        $engagement = $this->createEngagement($lead, $lead->people);
        $this->assertNotNull($engagement);

        $cacheKey = 'intellicheck_report_' . $lead->getId() . '_' . $lead->people->getId();
        Cache::forget($cacheKey);

        $this->capturingAction($lead->people, $lead)->execute(
            verificationData: $this->ocrOnlyPayload(),
            reuseExistingEngagement: true,
            engagement: $engagement,
        );

        $this->assertTrue(Cache::has($cacheKey), 'the existing callers keep their retry guard');
    }

    /**
     * No `idcheck`, so `toDriverLicenseScan()` returns null and the run stays confined to the report
     * path instead of writing licence fields onto the person.
     */
    private function ocrOnlyPayload(): array
    {
        return ['OCR' => ['data' => ['fullName' => 'Paul Fitzpatrick']]];
    }

    /**
     * Captures what the report step is handed, so the seam is asserted without `wkhtmltopdf` running.
     */
    private function capturingAction(People $people, Lead $lead): object
    {
        return new class ($people, $lead) extends VerifyPeopleIdAction {
            public ?Engagement $capturedEngagement = null;

            protected function generateReportPdf(
                array $reportData,
                bool $isShowRoom,
                ?Engagement $parentEngagement,
                ?array $images,
                bool $reuseExistingEngagement,
                ?Engagement $engagement = null
            ): ?Engagement {
                $this->capturedEngagement = $engagement;

                return $engagement;
            }
        };
    }

    private function writeScanPayload(Engagement $engagement, string $firstName, string $lastName): void
    {
        $message = $engagement->message;
        $message->message = [
            'status' => 'submitted',
            'verb' => ConfigurationEnum::ID_VERIFICATION->value,
            'data' => ['form' => ['private_data' => ['result' => [
                'idcheck' => ['data' => ['firstName' => $firstName, 'lastName' => $lastName]],
            ]]]],
        ];
        $message->saveOrFail();
        $message->refresh();
    }

    private function invokePayloadFromMessage(Message $message): array
    {
        return new ReflectionMethod(GenerateIdVerificationFromMessageActivity::class, 'payloadFromMessage')
            ->invoke(
                new ReflectionClass(GenerateIdVerificationFromMessageActivity::class)
                    ->newInstanceWithoutConstructor(),
                $message
            );
    }
}
