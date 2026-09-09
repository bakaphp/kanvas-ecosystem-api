<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Workflow;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\Actions\SubmitCreditApplicationAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class SubmitCreditApplicationActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @param Model<Message> $message
     */
    public function execute(Model $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $result = $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::CREDIT700,
            additionalParams: $params,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams): array {
                // The engagement row is written by a separate flow that can still be in-flight when
                // this activity picks up the message, so getEngagement() throws ModelNotFoundException.
                sleep(20);

                $result = new SubmitCreditApplicationAction($message)->execute();

                if (! $result['success']) {
                    $this->reportFailure($message, 'RouteOne rejected the credit application: ' . $this->rejectionReason($result['response']));

                    return $this->failWorkflow([
                        'message' => 'RouteOne rejected the credit application',
                        'success' => false,
                        'transaction_id' => $result['transaction_id'],
                        'token' => $result['token'],
                        'entity' => $result['response'],
                    ]);
                }

                return [
                    'message' => 'Credit application submitted to RouteOne successfully',
                    'success' => true,
                    'transaction_id' => $result['transaction_id'],
                    'token' => $result['token'],
                    'entity' => $result['response'],
                ];
            },
            company: $message->company,
        );

        // executeIntegration only report()s when the operation threw — those come back carrying a
        // trace. Its config bail-outs (no company / region / integration) return an error with no
        // trace and write no history row, so a credit app would vanish with nothing anywhere.
        if (isset($result['error']) && ! isset($result['trace'])) {
            $this->reportFailure($message, $result['error']);
        }

        return $result;
    }

    /**
     * Keep the payload out of this — it carries the applicant's SSN, DL and address.
     */
    private function reportFailure(Model $message, string $reason): void
    {
        report(new Exception(sprintf(
            'Credit700 credit application failed for message %s (app %s, company %s): %s',
            $message->getId(),
            $message->apps_id,
            $message->companies_id,
            $reason
        )));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function rejectionReason(array $response): string
    {
        $error = $response['Creditsystem_Error'] ?? null;

        if (is_array($error)) {
            $error = implode(
                ' ',
                array_map(
                    fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value),
                    $error
                )
            );
        }

        return is_string($error) && $error !== ''
            ? $error
            : 'no error returned, RouteOne gave back no transaction id';
    }
}
