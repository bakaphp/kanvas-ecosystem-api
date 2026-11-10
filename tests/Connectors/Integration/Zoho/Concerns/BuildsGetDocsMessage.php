<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho\Concerns;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;

trait BuildsGetDocsMessage
{
    protected function getDocsMessage(
        Lead $lead,
        Companies $company,
        string $status = ActionStatusEnum::SUBMITTED->value
    ): Message {
        $message = Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create([
                'users_id' => auth()->user()->getId(),
                'message' => [
                    'data' => [],
                    'text' => 'Get Docs',
                    'verb' => ActionEnum::GET_DOCS->value,
                    'status' => $status,
                ],
            ]);

        AppModuleMessage::create([
            'message_id' => $message->getId(),
            'message_types_id' => $message->message_types_id,
            'apps_id' => $message->apps_id,
            'companies_id' => $message->companies_id,
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'is_deleted' => 0,
        ]);

        return $message->fresh();
    }
}
