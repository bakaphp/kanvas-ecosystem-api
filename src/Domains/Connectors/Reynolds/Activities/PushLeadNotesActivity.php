<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Exception;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Reynolds counterpart of VinSolution's PushLeadNotesActivity — the entry
 * point ActionEngine uses when a task/message needs to reach the external
 * CRM. Mirrors the VinSolution surface so workflow config can swap the
 * activity class without changing shape or params.
 *
 * SalesAssist USL only exposes four sub-flows (Activity / Appointment /
 * Note / Consent), so the ActionEngine verbs that VinSolution pushes as
 * structured payloads (co-buyer, credit app, VOI, purchase vehicle, trade)
 * degrade here to a formatted USL Note — the only container R&R accepts
 * for arbitrary text. Purely local operations (ID verification address
 * update, esign cleanup) stay Kanvas-side and never round-trip to R&R.
 */
#[WorkflowAction]
class PushLeadNotesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $company = $message->company;

        if (empty($company->get(ConfigurationEnum::REYNOLDS_ENDPOINT->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_USERNAME->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_PASSWORD->value))
        ) {
            return ['error' => 'Reynolds credentials are not configured for this company'];
        }

        if (empty($company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value))
        ) {
            return ['error' => 'Reynolds dealer/store/area not configured for this company'];
        }

        $lead = $message->entity();
        if (! $lead instanceof Lead) {
            throw new Exception('Lead not found');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::REYNOLDS,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) use ($lead): array {
                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping Reynolds note push.',
                    ]);
                }

                $note = $this->extractNoteContent($message);

                $processMessageTaskUpdates = new ProcessMessageTaskUpdatesAction(
                    message: $message,
                    lead: $lead,
                    user: $message->user,
                )->execute();

                $primary = null;
                if ($note !== '') {
                    try {
                        $primary = new AddNoteToLeadAction($lead, $note)->execute();
                    } catch (Throwable $e) {
                        return $this->failWorkflow([
                            'error' => 'Reynolds USL Note failed: ' . $e->getMessage(),
                        ]);
                    }
                }

                $results = $this->processActionEngineMessage($message, $lead);

                return [
                    'note' => $primary,
                    'results' => $results,
                    'message' => 'Reynolds integration completed',
                    'taskUpdates' => $processMessageTaskUpdates,
                ];
            },
            company: $company,
        );
    }

    protected function processActionEngineMessage(Message $message, Lead $lead): array
    {
        $messageData = $message->getMessage();
        $verb = $messageData['verb'] ?? '';
        $status = $messageData['status'] ?? '';
        $results = [];

        switch ($verb) {
            case ActionEnum::TRADE_WALK->value:
            case ActionEnum::PAYOFF_FORM->value:
            case ActionEnum::ADD_TRADE->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $results['tradeIn'] = $this->pushDegradedNote(
                        $lead,
                        $this->buildTradeSummary($verb, $messageData)
                    );
                }

                break;
            case ActionEnum::CO_SIGNER->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    // SalesAssist has no co-buyer / second-customer field on
                    // either ISL or USL — see CoBuyerParticipantTest. The best
                    // we can offer is an unstructured Note describing the
                    // co-signer so a human on the R&R side can see it.
                    $results['coBuyer'] = $this->pushDegradedNote(
                        $lead,
                        $this->buildCoBuyerSummary($messageData)
                    );
                }

                break;
            case ActionEnum::CREDIT_APP->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $results['creditApp'] = $this->pushDegradedNote(
                        $lead,
                        'Credit application submitted via Kanvas — see attached credit form on our side.'
                    );
                }

                break;
            case ActionEnum::ID_VERIFICATION->value:
                if ($status === ActionStatusEnum::OPEN->value && ! empty($messageData['data']['address']['address'])) {
                    $results['idVerification'] = $this->idVerificationUpdatePeople($lead->people, $messageData);
                    $lead->set('dont-run-id-verification-manual', 1);
                }

                break;
            case ActionEnum::VIEW_PRODUCT->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    // See PushVoiToExistingLeadTest — USL has no Vehicle
                    // sub-flow, so VOI updates for an existing prospect land
                    // as an unstructured Note only.
                    $results['vehicleInterest'] = $this->pushDegradedNote(
                        $lead,
                        $this->buildVehicleInterestSummary($messageData)
                    );
                }

                break;
            case ActionEnum::PURCHASE_VEHICLE->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $results['purchaseVehicle'] = $this->pushDegradedNote(
                        $lead,
                        $this->buildPurchaseSummary($messageData)
                    );
                }

                break;
            case ActionEnum::ESIGN_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $this->cleanEsignature($lead);
                    $results['esignCleaned'] = true;
                }

                break;
            default:
                $results['action'] = 'No specific action for verb: ' . $verb;

                break;
        }

        return [
            'success' => true,
            'results' => $results,
            'message' => 'Reynolds integration completed',
        ];
    }

    public function idVerificationUpdatePeople(People $people, array $message): array
    {
        $people->addDefaultAddress(Address::from([
            'address' => $message['data']['address']['address'],
            'city' => $message['data']['address']['city'],
            'state' => $message['data']['address']['state'],
            'zip' => $message['data']['address']['zipcode'],
        ]));

        return $message['data'];
    }

    public function cleanEsignature(Lead $lead): void
    {
        $lead->set('prefill-sign-docs-' . time(), $lead->get('prefill-sign-docs'));
        $lead->set('actions_pdf_custom_form-' . time(), $lead->get('actions_pdf_custom_form'));

        $lead->del('prefill-sign-docs');
        $lead->del('actions_pdf_custom_form');
    }

    private function extractNoteContent(Message $message): string
    {
        $content = $message->message['content'] ?? '';

        return is_string($content) ? trim($content) : '';
    }

    private function pushDegradedNote(Lead $lead, string $note): array
    {
        try {
            return new AddNoteToLeadAction($lead, $note)->execute();
        } catch (Throwable $e) {
            return ['error' => 'Reynolds USL Note failed: ' . $e->getMessage()];
        }
    }

    private function buildTradeSummary(string $verb, array $messageData): string
    {
        $trade = $messageData['data']['trade'] ?? $messageData['data'] ?? [];
        $summary = [
            'year' => $trade['year'] ?? null,
            'make' => $trade['make'] ?? null,
            'model' => $trade['model'] ?? null,
            'vin' => $trade['vin'] ?? null,
            'odometer' => $trade['odometer'] ?? null,
        ];
        $summary = array_filter($summary, fn ($v) => $v !== null && $v !== '');

        $label = match ($verb) {
            ActionEnum::PAYOFF_FORM->value => 'Payoff form submitted',
            ActionEnum::ADD_TRADE->value => 'Trade-in added',
            default => 'Trade walk submitted',
        };

        if (empty($summary)) {
            return $label . '.';
        }

        return $label . ': ' . implode(' | ', array_map(
            fn ($k, $v) => ucfirst((string) $k) . '=' . (string) $v,
            array_keys($summary),
            $summary,
        ));
    }

    private function buildCoBuyerSummary(array $messageData): string
    {
        $co = $messageData['data']['co_signer'] ?? $messageData['data'] ?? [];
        $parts = array_filter([
            $co['firstname'] ?? null,
            $co['lastname'] ?? null,
            isset($co['phone']) ? 'phone ' . $co['phone'] : null,
            isset($co['email']) ? 'email ' . $co['email'] : null,
        ]);

        $tail = empty($parts) ? '' : ': ' . implode(' | ', $parts);

        return 'Co-signer submitted via Kanvas' . $tail;
    }

    private function buildVehicleInterestSummary(array $messageData): string
    {
        $vehicle = $messageData['data']['vehicle'] ?? $messageData['data'] ?? [];
        $summary = array_filter([
            'stock_type' => $vehicle['stock_type'] ?? null,
            'vin' => $vehicle['vin'] ?? null,
            'year' => $vehicle['year'] ?? null,
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($summary)) {
            return 'Vehicle interest updated via Kanvas.';
        }

        return 'Vehicle interest updated: ' . implode(' | ', array_map(
            fn ($k, $v) => ucfirst((string) str_replace('_', ' ', (string) $k)) . '=' . (string) $v,
            array_keys($summary),
            $summary,
        ));
    }

    private function buildPurchaseSummary(array $messageData): string
    {
        $vehicle = $messageData['data']['vehicle'] ?? $messageData['data'] ?? [];
        $summary = array_filter([
            'vin' => $vehicle['vin'] ?? null,
            'year' => $vehicle['year'] ?? null,
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
            'price' => $vehicle['price'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($summary)) {
            return 'Purchase agreement submitted via Kanvas.';
        }

        return 'Purchase agreement submitted: ' . implode(' | ', array_map(
            fn ($k, $v) => ucfirst((string) $k) . '=' . (string) $v,
            array_keys($summary),
            $summary,
        ));
    }
}
