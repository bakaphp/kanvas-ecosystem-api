<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Who created and who last modified each record.
 *
 * SIPGO has no `created_by` / `updated_by` columns anywhere — verified across all 43 migrations.
 * The only provenance is the `audits` table, where `username` is a **string, not an FK**. This is
 * also the question that started the whole Gestor effort ("quién creó y quién modificó cada
 * perfil"), and it is lost the moment SIPGO is switched off.
 *
 * This imports the *summary* — first create, last update — as custom fields, which is what
 * `rpt_ejecutivo.creado_por` / `modificado_por` need. The full per-field trail in
 * `audits_details` is a separate, still-open job; see the plan §1.8.
 */
class PullAuditProvenanceFromIntrasAction
{
    /**
     * Legacy model class → [Kanvas model, the custom field holding its legacy id].
     */
    public const array MODEL_MAP = [
        'Intras\Models\Participants' => [People::class, CustomFieldEnum::INTRAS_PARTICIPANT_ID],
        'Intras\Models\Companies' => [Organization::class, CustomFieldEnum::INTRAS_COMPANY_ID],
        'Intras\Models\Facilitators' => [People::class, CustomFieldEnum::INTRAS_FACILITATOR_ID],
        'Intras\Models\EventsVersions' => [EventVersion::class, CustomFieldEnum::INTRAS_EVENT_VERSION_ID],
        'Intras\Models\Quotes' => [Lead::class, CustomFieldEnum::INTRAS_QUOTE_ID],
    ];

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    /**
     * @return array<string, int> legacy model class => records given provenance
     */
    public function execute(): array
    {
        $client = new Client($this->app);
        $counts = [];

        foreach (self::MODEL_MAP as $legacyModel => [$kanvasModel, $customField]) {
            $counts[$legacyModel] = $this->applyProvenance(
                $client,
                $legacyModel,
                $kanvasModel,
                $customField->value,
            );
        }

        return $counts;
    }

    protected function applyProvenance(
        Client $client,
        string $legacyModel,
        string $kanvasModel,
        string $customFieldName
    ): int {
        $idMap = $this->legacyIdMap($kanvasModel, $customFieldName);

        if ($idMap === []) {
            return 0;
        }

        $count = 0;

        foreach ($this->provenanceFor($client, $legacyModel) as $legacyId => $provenance) {
            $kanvasId = $idMap[$legacyId] ?? null;

            if ($kanvasId === null) {
                continue;
            }

            $entity = $kanvasModel::query()
                ->where('id', $kanvasId)
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->first();

            if ($entity === null) {
                continue;
            }

            foreach ($provenance as $field => $value) {
                $entity->set($field, $value);
            }

            $count++;
        }

        return $count;
    }

    /**
     * First create and last update per legacy record, in two grouped queries rather than one per
     * record — `audits` is the largest table in the legacy schema.
     *
     * @return array<int, array<string, string>>
     */
    protected function provenanceFor(Client $client, string $legacyModel): array
    {
        $provenance = [];

        // type is C/U/D; the create row is the earliest C, the last edit the latest U.
        $creates = $client->table('audits')
            ->where('model_name', $legacyModel)
            ->where('type', 'C')
            ->selectRaw('model_id, MIN(created_at) as at, MIN(username) as username')
            ->groupBy('model_id')
            ->get();

        foreach ($creates as $row) {
            $provenance[(int) $row->model_id]['creado_por'] = trim((string) $row->username);
            $provenance[(int) $row->model_id]['creado_en'] = (string) $row->at;
        }

        $updates = $client->table('audits as a')
            ->where('a.model_name', $legacyModel)
            ->where('a.type', 'U')
            ->whereRaw('a.created_at = (
                SELECT MAX(b.created_at) FROM audits b
                WHERE b.model_name = a.model_name AND b.model_id = a.model_id AND b.type = "U"
            )')
            ->select('a.model_id', 'a.username', 'a.created_at')
            ->get();

        foreach ($updates as $row) {
            $provenance[(int) $row->model_id]['modificado_por'] = trim((string) $row->username);
            $provenance[(int) $row->model_id]['modificado_en'] = (string) $row->created_at;
        }

        return array_map(
            fn (array $fields) => array_filter($fields, fn ($value) => $value !== ''),
            $provenance
        );
    }

    /**
     * @return array<int, int> legacy id => kanvas id
     */
    protected function legacyIdMap(string $modelClass, string $customFieldName): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', $modelClass)
            ->where('name', $customFieldName)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($kanvasId, $legacyId) => [(int) $legacyId => (int) $kanvasId])
            ->all();
    }
}
