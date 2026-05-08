<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Notifications\NewLeadNotification;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class SendLeadEmailsAction
{
    /** @var array<string,string> */
    private array $typeNameCache = [];

    public function __construct(
        private readonly Lead $lead,
        private readonly string $emailTemplate,
        private readonly array $channels = ['mail'],
    ) {
    }

    public function execute(
        array $payload,
        array $users,
        LeadNotificationModeEnum $notificationMode = LeadNotificationModeEnum::NOTIFY_ALL,
    ): void {
        $userTemplate = 'user-' . $this->emailTemplate;
        $leadTemplate = 'lead-' . $this->emailTemplate;
        $leadEmail = $this->lead->people?->getEmails()->first()?->value;
        $data = $this->buildTemplateData($payload);

        if ($notificationMode->shouldNotifyAgents()) {
            foreach ($users as $user) {
                $this->safeSend(
                    $user,
                    $userTemplate,
                    $user->email,
                    $data
                );
            }
        }

        if ($notificationMode->shouldNotifyLead() && $leadEmail) {
            $this->safeSend(
                $this->lead,
                $leadTemplate,
                $leadEmail,
                $data,
            );
        }

        foreach ($this->parseExtraEmails($payload['extraEmails'] ?? null) as $email) {
            $this->safeSend(
                $this->lead,
                $userTemplate,
                $email,
                $data
            );
        }
    }

    public function getProduct(string $productId): object
    {
        $product = Products::with(['variants', 'variants.warehouses'])->where('id', $productId)->first();
        $variant = $product->variants->first();
        $warehouse = $variant->warehouses->first();
        $defaultChannel = $variant->channels->first();

        return (object) [
            'name' => $product->name,
            'price' => $variant->getPrice($warehouse, $defaultChannel),
            'quantity' => $variant->quantity,
        ];
    }

    private function buildTemplateData(array $payload): array
    {
        return [
            ...$payload,
            'lead' => $this->lead,
            'company' => $this->lead->company,
            'app' => $this->lead->app,
        ];
    }

    /**
     * @param string|array<string>|null $value
     * @return list<string>
     */
    private function parseExtraEmails(string|array|null $value): array
    {
        if (empty($value)) {
            return [];
        }

        $list = is_array($value) ? $value : explode(',', $value);

        return array_values(array_filter(array_map('trim', $list)));
    }

    private function safeSend(
        Model $entity,
        string $template,
        string $email,
        array $data,
    ): void {
        try {
            $this->sendEmail(
                $entity,
                $template,
                $email,
                $data,
            );
        } catch (Exception $e) {
            report($e);
        }
    }

    private function sendEmail(
        Model $entity,
        string $template,
        string $email,
        array $data,
    ): void {
        $notification = new NewLeadNotification($this->lead, $data);
        $notification->setTemplateName($template);
        $notification->setType($this->resolveNotificationTypeName($template));
        $notification->channels = $this->channels;

        if ($entity instanceof Users) {
            $entity->notify($notification);

            return;
        }

        Notification::route('mail', $email)->notify($notification);
    }

    private function resolveNotificationTypeName(string $template): string
    {
        $appId = $this->lead->app->getId();
        $key = $appId . ':' . $template;

        return $this->typeNameCache[$key] ??= NotificationTypes::firstOrCreate(
            [
                'apps_id' => $appId,
                'key' => Lead::class,
                'name' => Str::simpleSlug(Lead::class),
                'system_modules_id' => SystemModulesRepository::getByModelName(Lead::class, $this->lead->app)->getId(),
                'is_deleted' => 0,
            ],
            [
                'template' => $template,
            ],
        )->name;
    }
}
