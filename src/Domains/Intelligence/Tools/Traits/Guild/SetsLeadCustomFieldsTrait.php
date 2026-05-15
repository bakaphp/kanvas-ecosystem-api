<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

trait SetsLeadCustomFieldsTrait
{
    protected function setLeadCustomFields(
        AppInterface $app,
        CompanyInterface $company,
        int $leadId,
        array $fields,
    ): array {
        if (empty($fields)) {
            return ['error' => 'No fields provided. Pass a "fields" array with key/value pairs.'];
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $company, $app);
        } catch (Throwable $e) {
            return ['error' => "Lead {$leadId} not found: {$e->getMessage()}"];
        }

        foreach ($fields as $key => $value) {
            $lead->set((string) $key, $value);
        }

        return [
            'success' => true,
            'lead_id' => $leadId,
            'fields_set' => count($fields),
        ];
    }
}
