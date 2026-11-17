<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\EnsureDeliveryOrderNoticeRuleAction;
use Kanvas\Souk\Orders\Actions\SetupDeliveryOrderTypeAction;
use Kanvas\Souk\Orders\Enums\DeliveryOrderTypeEnum;

class SetupDeliveryOrderTypeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:souk-setup-delivery-order-type {app_id?} {--company= : Also create the status notice rule for this company id}';

    protected $description = 'Create the delivery order type with its statuses and transitions, and optionally the status notice rule for a company';

    public function handle(): int
    {
        $app = $this->argument('app_id') ? Apps::getById((int) $this->argument('app_id')) : app(Apps::class);
        $this->overwriteAppService($app);

        $result = new SetupDeliveryOrderTypeAction($app)->execute();

        $this->info('Order type ' . DeliveryOrderTypeEnum::DELIVERY->value . " set up for app: {$app->name}");
        $this->table(
            ['Status', 'Slug', 'Default', 'Final'],
            collect($result['statuses'])->map(
                fn ($status): array => [$status->name, $status->slug, $status->is_default ? 'yes' : 'no', $status->is_final ? 'yes' : 'no']
            )->all()
        );

        if ($this->option('company')) {
            $this->ensureStatusNoticeRule($app, Companies::getById((int) $this->option('company')));
        }

        return self::SUCCESS;
    }

    private function ensureStatusNoticeRule(Apps $app, Companies $company): void
    {
        $rule = new EnsureDeliveryOrderNoticeRuleAction($app, $company)->execute();

        $rule === null
            ? $this->warn('Status notice rule skipped: run kanvas:workflow-sync-actions first')
            : $this->info("Status notice rule ready for company {$company->getId()}");
    }
}
