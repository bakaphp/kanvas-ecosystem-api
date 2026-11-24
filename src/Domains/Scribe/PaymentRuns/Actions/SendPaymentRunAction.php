<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunStatusEnum;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRun;
use Kanvas\Scribe\PaymentRuns\Services\PaymentRunPresenterService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

/**
 * Hands the run to the person who pays. The payer must be a member of the run's company: the email lists
 * vendors, amounts and bank accounts, so it never goes to an address the model typed.
 */
class SendPaymentRunAction
{
    public function __construct(
        public readonly PaymentRun $run,
        public readonly Users $payer,
        private readonly PaymentRunPresenterService $presenter = new PaymentRunPresenterService(),
    ) {
    }

    public function execute(): PaymentRun
    {
        $run = $this->run;
        $company = $run->company;

        try {
            UsersRepository::belongsToCompany($this->payer, $company);
        } catch (Throwable) {
            throw new ValidationException("{$this->payer->email} is not a member of this company, so the payment run was not sent.");
        }

        $data = [
            ...$this->presenter->toArray($run),
            'company_name' => $company->name,
            'payer_name' => $this->payer->fullName() ?: $this->payer->email,
        ];

        $notification = new Blank(
            ScribeEmailTemplateEnum::PAYMENT_RUN->value,
            $data,
            ['mail'],
            $company
        );
        $notification->setSubject(sprintf(
            '%s — payment run #%d: %d bill(s) to pay',
            $company->name,
            $run->getId(),
            $run->line_count
        ));

        Notification::send([$this->payer], $notification);

        if ($run->status === PaymentRunStatusEnum::DRAFT) {
            $run->status = PaymentRunStatusEnum::SENT;
        }
        $run->sent_to_users_id = $this->payer->getId();
        $run->sent_at = Carbon::now();
        $run->save();

        $run->emitLedgerEvent('scribe.payment_run.sent', payload: [
            'payment_run_id' => $run->getId(),
            'sent_to_users_id' => $this->payer->getId(),
            'line_count' => $run->line_count,
        ]);

        return $run;
    }
}
