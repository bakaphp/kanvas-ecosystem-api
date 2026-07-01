<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mailgun;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Actions\ValidatePeopleEmailAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

class ValidateAllPeopleEmailsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * Mailgun caps each account at 300 requests/minute. Validating one person can
     * issue up to one request per email contact, so we reserve that much headroom
     * before each person to keep the actual request rate under the configured limit.
     */
    private const int MAX_REQUESTS_PER_PERSON = 3;

    private const int RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-mailgun-email-validate {app_id} {company_id} {total=150} {perPage=50} {--order=desc : Sort people by id (asc|desc)} {--cooldown=0 : Skip people validated within N days (0 = validate once, never re-validate)} {--force : Re-validate everyone, ignoring the cooldown} {--rate-limit=250 : Max Mailgun validation requests per minute (Mailgun caps the account at 300)}';

    /** @var list<float> Unix timestamps (with microseconds) of validation requests issued within the trailing window. */
    private array $requestWindow = [];

    private int $rateLimit = 250;

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Validate the email addresses of all people in a company with Mailgun, flagging hard bounces / invalid addresses';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        if ((string) $app->get(ConfigurationEnum::API_KEY->value) === '') {
            $this->error("No MAILGUN_API_KEY configured for app {$app->name}; nothing to validate.");

            return self::FAILURE;
        }

        $perPage = (int) $this->argument('perPage');
        $total = (int) $this->argument('total');
        $order = strtolower((string) $this->option('order')) === 'asc' ? 'ASC' : 'DESC';
        $force = (bool) $this->option('force');
        $cooldownDays = max(0, (int) $this->option('cooldown'));
        $this->rateLimit = max(1, (int) $this->option('rate-limit'));

        $this->line("Validating emails for company {$company->name} from app {$app->name}, total {$total}, per page {$perPage}, order {$order}, rate limit {$this->rateLimit}/min");

        People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->orderBy('peoples.id', $order)
            ->limit($total)
            ->chunk($perPage, function ($peoples) use ($app, $force, $cooldownDays) {
                foreach ($peoples as $people) {
                    if (! $force && ValidatePeopleEmailAction::isWithinValidationCooldown($people, $cooldownDays)) {
                        $this->line("Skipping people {$people->id}: already validated");

                        continue;
                    }

                    $this->awaitRateLimitSlot();

                    try {
                        $validated = new ValidatePeopleEmailAction($people, $app)->execute()['validated'];
                        $this->recordRequests(count($validated));

                        if ($validated === []) {
                            $this->line("People {$people->id}: no email to validate");

                            continue;
                        }

                        $summary = implode(', ', array_map(fn (array $row): string => (string) $row['result'], $validated));
                        $this->line("People {$people->id}: " . count($validated) . " email(s) -> {$summary}");
                    } catch (Throwable $e) {
                        report($e);
                        $this->line("People {$people->id}: validation failed ({$e->getMessage()})");
                    }
                }
            });

        $this->line("All emails for company {$company->name} from app {$app->name} validated");

        return self::SUCCESS;
    }

    /**
     * Block until the trailing window has room for the next person's requests, so the
     * account-wide Mailgun rate limit (300/min) is never tripped.
     */
    private function awaitRateLimitSlot(): void
    {
        $headroom = min(self::MAX_REQUESTS_PER_PERSON, $this->rateLimit);

        $now = microtime(true);
        $this->pruneRequestWindow($now);

        while (count($this->requestWindow) + $headroom > $this->rateLimit) {
            $sleepFor = (float) self::RATE_LIMIT_WINDOW_SECONDS - ($now - $this->requestWindow[0]);
            if ($sleepFor > 0.0) {
                usleep((int) ceil($sleepFor * 1_000_000));
            }

            $now = microtime(true);
            $this->pruneRequestWindow($now);
        }
    }

    private function recordRequests(int $count): void
    {
        $now = microtime(true);
        for ($i = 0; $i < $count; $i++) {
            $this->requestWindow[] = $now;
        }
    }

    private function pruneRequestWindow(float $now): void
    {
        $cutoff = $now - (float) self::RATE_LIMIT_WINDOW_SECONDS;
        $this->requestWindow = array_values(array_filter(
            $this->requestWindow,
            fn (float $timestamp): bool => $timestamp > $cutoff,
        ));
    }
}
