<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe\Schedules;

use App\Console\Commands\Scribe\DispatchInvoiceFollowUpsCommand;
use App\Console\Commands\Scribe\EvaluateInvoiceAgingCommand;
use App\Console\Commands\Scribe\FlagUnconfirmedBillPaymentsCommand;
use App\Console\Commands\Scribe\RequestBankStatementsCommand;
use App\Console\Commands\Scribe\ResolveStuckFiscalSubmissionsCommand;
use App\Console\Commands\Scribe\RetryDocumentSignalsCommand;
use App\Console\Commands\Scribe\RunBillingSchedulesCommand;
use App\Console\Commands\Scribe\SendAccountingDigestCommand;
use App\Console\Commands\Scribe\SendReimbursementDigestCommand;
use App\Console\Commands\Scribe\SyncFxRatesCommand;
use App\Console\Commands\Scribe\WatchDocumentEngagementsCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Scribe domain cron registry. Wired into Kernel.php::schedule().
 *
 * Timing notes (America/New_York anchor — financial domain runs on US business hours):
 *   01:15 NY  EvaluateInvoiceAgingCommand
 *             — well after midnight so freshly-issued invoices "yesterday" count;
 *             — ahead of NervousSystem Dashboard rollup (00:30) is fine since dashboard
 *               doesn't depend on Scribe aging state today.
 *   02:00 NY  RunBillingSchedulesCommand
 *             — after aging; drafts land before business hours so approvers see them in the morning.
 *   1st 08:00 NY  SendReimbursementDigestCommand
 *             — monthly, on the 1st, so it reports a month that has actually closed;
 *             — business hours because it is read by employees, not by a system.
 *   10th / 20th / last day 09:00 NY  RequestBankStatementsCommand
 *             — the agent asks for missing statement movements three times a month (plan §8.1).
 *   02:15 NY  FlagUnconfirmedBillPaymentsCommand
 *             — after the overnight statement ingests, so a payment confirmed last night is not flagged.
 *   07:45 NY  SendAccountingDigestCommand
 *             — yesterday's AR/AP digest, after aging and the unconfirmed-payment flags it reports on.
 *   09:30 NY  DispatchInvoiceFollowUpsCommand
 *             — dunning reminders land in the customer's morning, after aging marked what is overdue.
 *   10:00 NY  WatchDocumentEngagementsCommand
 *             — quiet quotes and unopened documents become AR agent tasks during business hours.
 *   18:30 + 21:30 Santo Domingo  SyncFxRatesCommand
 *             — the Banco Central publishes around 18:10 local; the late run catches a delayed table. Idempotent
 *               per published date, so the second run changes nothing on a normal day.
 *   every 10 min  ResolveStuckFiscalSubmissionsCommand
 *             — an e-CF send whose worker died mid-call is turned into a status query within minutes.
 *   hourly :20  RetryDocumentSignalsCommand
 *             — a document signal whose task did not open (no AR agent yet) is handed over once there is one.
 */
final class ScribeSchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(EvaluateInvoiceAgingCommand::class)
            ->dailyAt('01:15')
            ->timezone('America/New_York')
            ->withoutOverlapping(720)
            ->onOneServer();

        $schedule->command(RunBillingSchedulesCommand::class)
            ->dailyAt('02:00')
            ->timezone('America/New_York')
            ->withoutOverlapping(720)
            ->onOneServer();

        foreach ([10, 20] as $day) {
            $schedule->command(RequestBankStatementsCommand::class)
                ->monthlyOn($day, '09:00')
                ->timezone('America/New_York')
                ->withoutOverlapping()
                ->onOneServer();
        }

        $schedule->command(RequestBankStatementsCommand::class)
            ->lastDayOfMonth('09:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        foreach (['18:30', '21:30'] as $time) {
            $schedule->command(SyncFxRatesCommand::class)
                ->dailyAt($time)
                ->timezone('America/Santo_Domingo')
                ->withoutOverlapping()
                ->onOneServer();
        }

        $schedule->command(FlagUnconfirmedBillPaymentsCommand::class)
            ->dailyAt('02:15')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(SendAccountingDigestCommand::class)
            ->dailyAt('07:45')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(DispatchInvoiceFollowUpsCommand::class)
            ->dailyAt('09:30')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(WatchDocumentEngagementsCommand::class)
            ->dailyAt('10:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(ResolveStuckFiscalSubmissionsCommand::class)
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(RetryDocumentSignalsCommand::class)
            ->hourlyAt(20)
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(SendReimbursementDigestCommand::class)
            ->monthlyOn(1, '08:00')
            ->timezone('America/New_York')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
