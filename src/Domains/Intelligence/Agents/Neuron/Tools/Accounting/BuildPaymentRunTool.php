<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\PaymentRuns\Actions\BuildPaymentRunAction;
use Kanvas\Scribe\PaymentRuns\Actions\SendPaymentRunAction;
use Kanvas\Scribe\PaymentRuns\DataTransferObject\PaymentRun as PaymentRunData;
use Kanvas\Scribe\PaymentRuns\Enums\ConfigurationEnum;
use Kanvas\Scribe\PaymentRuns\Services\PaymentRunPresenterService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * The payer is never a free address: an email the model passes must belong to a member of the company
 * (SendPaymentRunAction checks it), otherwise the company's configured payer, otherwise its owner.
 */
#[AgentTool(name: 'Build Payment Run', category: 'accounting')]
class BuildPaymentRunTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;

    private const int DEFAULT_HORIZON_DAYS = 7;

    protected string $name = 'build_payment_run';

    protected ?string $description = 'Prepares a payment run for the person who pays: the approved, unpaid vendor bills due by a '
        . 'date (or the bills you pick), grouped by currency and the bank account they would be paid from, with a check '
        . 'of that account\'s known balance against the total. Sends it to the person who pays by email and returns a '
        . 'summary to post in chat. You never move money: the person pays, then tells you, and you record each payment '
        . 'with record_bill_payment; the run follows those payments. A bill already on an open run is left out.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'due_by',
                type: PropertyType::STRING,
                description: 'Include bills due on or before this date, YYYY-MM-DD. Omit for the next 7 days. Ignored when bill_ids is given.',
                required: false,
            ),
            new ToolProperty(
                name: 'bill_ids',
                type: PropertyType::STRING,
                description: 'Comma-separated bill ids to pay, when the person picked specific bills (from list_open_bills).',
                required: false,
            ),
            new ToolProperty(
                name: 'payer_email',
                type: PropertyType::STRING,
                description: 'Email of the teammate who pays, when the person named one. Must be a member of the company. Omit to use the company\'s payer.',
                required: false,
            ),
            new ToolProperty(
                name: 'send',
                type: PropertyType::BOOLEAN,
                description: 'Email the run to the person who pays. Defaults to true; false only builds the draft for review.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'A short note for the person who pays.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $due_by = null,
        ?string $bill_ids = null,
        ?string $payer_email = null,
        ?bool $send = null,
        ?string $notes = null,
    ): array {
        return $this->oncePerTurn(
            ['due_by' => $due_by, 'bill_ids' => $bill_ids],
            fn (): array => $this->build(
                $due_by,
                $bill_ids,
                $payer_email,
                $send ?? true,
                $notes,
            ),
            note: 'This payment run was already built earlier in this turn. Nothing new was created; report the run shown here.',
            rememberFailures: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(
        ?string $dueBy,
        ?string $billIds,
        ?string $payerEmail,
        bool $send,
        ?string $notes
    ): array {
        $date = $this->dueBy($dueBy);
        if ($date === null) {
            return $this->invalidArgs("due_by must be a date like 2026-06-30 (got \"{$dueBy}\").", ['built' => false]);
        }

        $ids = $this->billIds($billIds);
        if ($ids === []) {
            return $this->invalidArgs('bill_ids must be bill ids separated by commas, e.g. "12,15".', ['built' => false]);
        }

        $payer = null;
        if ($send) {
            $payer = $this->payer($payerEmail);
            if (is_array($payer)) {
                return $payer;
            }
        }

        try {
            $run = new BuildPaymentRunAction(new PaymentRunData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                dueBy: $date,
                billIds: $ids,
                agentId: $this->actingAgent?->getId(),
                notes: Str::trimToNull($notes),
            ))->execute();

            if ($payer !== null) {
                $run = new SendPaymentRunAction($run, $payer)->execute();
            }
        } catch (ValidationException $e) {
            return $this->noop(['built' => false, 'message' => $e->getMessage()], 'Tell the person; do not retry with the same input.');
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The payment run could not be built.', ['built' => false], 'Say the run was NOT built or sent.');
        }

        $presenter = new PaymentRunPresenterService();

        return $this->ok([
            'built' => true,
            'sent_to' => $payer?->email,
            ...$presenter->toArray($run),
            'summary' => $presenter->summary($run),
        ], $payer !== null
            ? 'Post the summary in chat and say it was emailed to the person who pays. Remind them to tell you once each bill is paid.'
            : 'Post the summary in chat; it was NOT emailed.');
    }

    private function dueBy(?string $value): ?Carbon
    {
        $value = Str::trimToNull($value);

        if ($value === null) {
            return Carbon::today()->addDays(self::DEFAULT_HORIZON_DAYS);
        }

        $date = DateHelper::parseYmd($value);

        return $date === null ? null : Carbon::instance($date);
    }

    /**
     * @return list<int>|null null when no selection was given, [] when the selection was unreadable
     */
    private function billIds(?string $value): ?array
    {
        $value = Str::trimToNull($value);

        if ($value === null) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $id): int => (int) trim($id), explode(',', $value)),
            fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @return Users|array<string, mixed>
     */
    private function payer(?string $email): Users|array
    {
        $email = Str::trimToNull($email);

        if ($email !== null) {
            try {
                return UsersRepository::getUserOfAppByEmail($email, $this->app);
            } catch (Throwable) {
                return $this->denied(
                    "{$email} is not a user of this app, so the run cannot be sent to them.",
                    ['built' => false],
                    'Ask for the email of a teammate in this company, or omit payer_email.'
                );
            }
        }

        $configured = (int) $this->company->get(ConfigurationEnum::PAYER_USER_ID->value);
        $payer = $configured > 0 ? Users::query()->where('id', $configured)->where('is_deleted', 0)->first() : null;

        return $payer ?? $this->company->user ?? $this->denied(
            'This company has no person who pays configured and no owner on file.',
            ['built' => false],
        );
    }
}
