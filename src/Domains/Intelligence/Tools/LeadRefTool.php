<?php

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class LeadRefTool implements ContextToolInterface
{
    public function __construct(public Model $model)
    {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        return [
            'lead_id' => $this->model->id,
            'source_type' => $this->model->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) ?? '',
            'timezone' => $this->model->get('timezone') ?? '',
            'created_at' => $this->model->created_at?->toIso8601String(),
        ];
    }
}
