<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Exception;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
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
 * Reynolds counterpart of VinSolution's PushLeadNotesActivity.
 *
 * If the message's ActionEngine verb matches one of the known cases, a
 * formatted summary is pushed as a USL Note (SalesAssist has no structured
 * sub-flows for co-buyer, credit-app, VOI, purchase or trade so those
 * always degrade to text). ID verification and esign clean-up are Kanvas-
 * local and never round-trip.
 *
 * If the verb doesn't match any known case, the raw message content is
 * pushed as-is. That covers plain agent notes and any future verb we
 * haven't wired yet, so nothing gets silently swallowed.
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

                $branch = $this->dispatchByVerb($message, $lead);

                return [
                    'branch' => $branch,
                    'message' => 'Reynolds integration completed',
                ];
            },
            company: $company,
        );
    }

    private function dispatchByVerb(Message $message, Lead $lead): array
    {
        $messageData = $message->getMessage();
        $verb = (string) ($messageData['verb'] ?? '');
        $status = (string) ($messageData['status'] ?? '');

        switch ($verb) {
            case ActionEnum::TRADE_WALK->value:
            case ActionEnum::PAYOFF_FORM->value:
            case ActionEnum::ADD_TRADE->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    return $this->pushNote($lead, $this->buildTradeSummary($verb, $messageData), $verb);
                }

                break;
            case ActionEnum::CO_SIGNER->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    // SalesAssist has no co-buyer / second-customer field on
                    // either ISL or USL — see CoBuyerParticipantTest. The best
                    // we can offer is an unstructured Note describing the
                    // co-signer so a human on the R&R side can see it.
                    return $this->pushNote($lead, $this->buildCoBuyerSummary($messageData), $verb);
                }

                break;
            case ActionEnum::CREDIT_APP->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    return $this->pushNote(
                        $lead,
                        'Credit application submitted via Kanvas — see attached credit form on our side.',
                        $verb,
                    );
                }

                break;
            case ActionEnum::ID_VERIFICATION->value:
                if ($status === ActionStatusEnum::OPEN->value && ! empty($messageData['data']['address']['address'])) {
                    $updated = $this->idVerificationUpdatePeople($lead->people, $messageData);
                    $lead->set('dont-run-id-verification-manual', 1);

                    return ['verb' => $verb, 'local' => true, 'idVerification' => $updated];
                }

                break;
            case ActionEnum::VIEW_PRODUCT->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    // See PushVoiToExistingLeadTest — USL has no Vehicle sub-flow,
                    // so VOI updates on an existing prospect can only land as an
                    // unstructured Note.
                    return $this->pushNote($lead, $this->buildVehicleInterestSummary($messageData), $verb);
                }

                break;
            case ActionEnum::PURCHASE_VEHICLE->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    return $this->pushNote($lead, $this->buildPurchaseSummary($messageData), $verb);
                }

                break;
            case ActionEnum::ESIGN_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $this->cleanEsignature($lead);

                    return ['verb' => $verb, 'local' => true, 'esignCleaned' => true];
                }

                break;
        }

        // No structured verb matched (or matched but status wasn't ready) —
        // fall back to pushing the raw message content so nothing gets
        // silently dropped.
        return $this->pushNote($lead, $this->extractNoteContent($message), $verb !== '' ? $verb : 'raw');
    }

    private function pushNote(Lead $lead, string $note, string $verb): array
    {
        $note = trim($note);
        if ($note === '') {
            return ['verb' => $verb, 'skipped' => 'empty note'];
        }

        try {
            $result = new AddNoteToLeadAction($lead, $note)->execute();
        } catch (Throwable $e) {
            return ['verb' => $verb, 'error' => 'Reynolds USL Note failed: ' . $e->getMessage()];
        }

        return ['verb' => $verb, 'note' => $result];
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

    private function buildTradeSummary(string $verb, array $messageData): string
    {
        $trade = $messageData['data']['trade'] ?? $messageData['data'] ?? [];
        $summary = array_filter([
            'year' => $trade['year'] ?? null,
            'make' => $trade['make'] ?? null,
            'model' => $trade['model'] ?? null,
            'vin' => $trade['vin'] ?? null,
            'odometer' => $trade['odometer'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $label = match ($verb) {
            ActionEnum::PAYOFF_FORM->value => 'Payoff form submitted',
            ActionEnum::ADD_TRADE->value => 'Trade-in added',
            default => 'Trade walk submitted',
        };

        return empty($summary)
            ? $label . '.'
            : $label . ': ' . $this->joinSummary($summary);
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

        return 'Co-signer submitted via Kanvas'
            . (empty($parts) ? '' : ': ' . implode(' | ', $parts));
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

        return empty($summary)
            ? 'Vehicle interest updated via Kanvas.'
            : 'Vehicle interest updated: ' . $this->joinSummary($summary);
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

        return empty($summary)
            ? 'Purchase agreement submitted via Kanvas.'
            : 'Purchase agreement submitted: ' . $this->joinSummary($summary);
    }

    private function joinSummary(array $summary): string
    {
        return implode(' | ', array_map(
            fn ($k, $v) => ucfirst((string) str_replace('_', ' ', (string) $k)) . '=' . (string) $v,
            array_keys($summary),
            $summary,
        ));
    }
}
