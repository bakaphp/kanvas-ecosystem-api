<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\ScribePdfInboundWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Read side of the company accounting inbox: which receiver it is and the address it receives on.
 *
 * The address, in order:
 *   - `accounting@<company-slug>.<platform inbound domain>` when `services.mailgun.accounting_inbox_domain`
 *     is set — the tenant configures no Mailgun at all;
 *   - `accounting@<company domain>` when the company has its own Mailgun domain;
 *   - `accounting-<company-slug>@<app / platform domain>` otherwise, because that domain is shared by
 *     every company on it.
 * Once stored on the receiver the address never changes: vendors and the Mailgun route already use it.
 */
class AccountingInboxService
{
    public const string LOCAL_PART = 'accounting';

    public function receiverFor(AppInterface $app, CompanyInterface $company): ?ReceiverWebhook
    {
        /** @var ReceiverWebhook|null $receiver */
        $receiver = ReceiverWebhook::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->forJob(ScribePdfInboundWebhookJob::class)
            ->orderBy('id')
            ->get()
            ->first(fn (ReceiverWebhook $receiver): bool => self::isAccountingInbox($receiver));

        return $receiver;
    }

    public function storedAddress(AppInterface $app, CompanyInterface $company): ?string
    {
        $receiver = $this->receiverFor($app, $company);

        return $receiver !== null ? self::addressOn($receiver) : null;
    }

    /**
     * The gate ScribePdfInboundWebhookJob enforces: without it any Mailgun route pointed at that action
     * would turn every PDF it receives into a bill.
     */
    public static function isAccountingInbox(ReceiverWebhook $receiver): bool
    {
        return filter_var(
            $receiver->configuration[ReceiverConfigurationEnum::SCRIBE_PDF_INBOX->value] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public static function addressOn(ReceiverWebhook $receiver): ?string
    {
        return Str::trimToNull($receiver->configuration[ReceiverConfigurationEnum::INBOX_ADDRESS->value] ?? null);
    }

    public static function routeOn(ReceiverWebhook $receiver): ?string
    {
        return Str::trimToNull($receiver->configuration[ReceiverConfigurationEnum::ROUTE_ID->value] ?? null);
    }

    public function proposedAddressFor(AppInterface $app, CompanyInterface $company): string
    {
        $slug = $this->companySlug($company);
        $platformDomain = strtolower(trim((string) config('services.mailgun.accounting_inbox_domain')));

        if ($platformDomain !== '') {
            return self::LOCAL_PART . '@' . $slug . '.' . $platformDomain;
        }

        $ownDomain = strtolower(trim((string) $company->get(ConfigurationEnum::DOMAIN->value)));
        if ($ownDomain !== '') {
            return self::LOCAL_PART . '@' . $ownDomain;
        }

        return self::LOCAL_PART . '-' . $slug . '@' . new AgentMailboxService()->domainForTenant($app, $company);
    }

    public function workflowAction(): ?WorkflowAction
    {
        /** @var WorkflowAction|null $action */
        $action = WorkflowAction::query()->where('model_name', ScribePdfInboundWebhookJob::class)->first();

        return $action;
    }

    /**
     * A company name can be shared, an address cannot: the id rides along whenever another company's
     * inbox already claimed the plain slug.
     */
    private function companySlug(CompanyInterface $company): string
    {
        $slug = trim((string) Str::slug((string) $company->name));
        if ($slug === '') {
            return 'company-' . $company->getId();
        }

        $prefixes = [self::LOCAL_PART . '@' . $slug . '.', self::LOCAL_PART . '-' . $slug . '@'];
        $taken = ReceiverWebhook::query()
            ->where('companies_id', '!=', $company->getId())
            ->where('is_deleted', 0)
            ->where('configuration', 'like', '%' . ReceiverConfigurationEnum::INBOX_ADDRESS->value . '%')
            ->get(['configuration'])
            ->contains(function (ReceiverWebhook $receiver) use ($prefixes): bool {
                $address = (string) self::addressOn($receiver);

                return array_any($prefixes, fn (string $prefix): bool => str_starts_with($address, $prefix));
            });

        return $taken ? $slug . '-' . $company->getId() : $slug;
    }
}
