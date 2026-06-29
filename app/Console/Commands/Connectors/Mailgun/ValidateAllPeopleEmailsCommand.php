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
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-mailgun-email-validate {app_id} {company_id} {total=150} {perPage=50} {--order=desc : Sort people by id (asc|desc)} {--cooldown=0 : Skip people validated within N days (0 = validate once, never re-validate)} {--force : Re-validate everyone, ignoring the cooldown}';

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

        $this->line("Validating emails for company {$company->name} from app {$app->name}, total {$total}, per page {$perPage}, order {$order}");

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

                    try {
                        $validated = new ValidatePeopleEmailAction($people, $app)->execute()['validated'];

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
}
