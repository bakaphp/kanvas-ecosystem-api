<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Illuminate\Support\Collection;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Neuron\Tools\Concerns\ResolvesSipgoVersion;
use Kanvas\Connectors\Intras\Support\CustomFieldsBatch;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * SIPGO's "Participantes" tab for an edition: one row per inscription with the PA code, empresa, cargo
 * and tipo de inscripción the person expects to see there.
 *
 * Empresa and cargo are registration-grain in SIPGO and can differ from the participant's own record
 * (PA80239 is PARALLAX on her card and SEMMA on her VE7160 inscription). The import does not keep that
 * snapshot yet, so a row reads `metadata.empresa` / `metadata.posicion` when present and falls back to
 * the People record, and the note says which it is.
 */
#[AgentTool(name: 'Intras Event Registrations', category: 'reporting')]
class EventRegistrationsTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSipgoVersion;
    use TrackByInputs;

    protected string $name = 'intras_inscritos';

    protected ?string $description = 'Lista de inscritos de una versión de evento tal como se ve en SIPGO: '
        . 'código PA, nombre, empresa, cargo, tipo de inscripción, email y asistencia. Acepta un enlace de '
        . 'SIPGO, un código "VE7160" o el número de la versión en referencia, o el version_id que '
        . 'devolvió otra herramienta. Úsala cuando pidan "los participantes de este evento" con un enlace.';

    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            ...$this->versionReferenceProperties(),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Máximo de inscritos. Por defecto 50, tope 200.',
                required: false,
            ),
            ...$this->tenantCompanyProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $referencia = null,
        ?int $version_id = null,
        ?int $limit = null,
        ?int $tenant_company_id = null
    ): array {
        if (($refusal = $this->narrowReadScope($tenant_company_id)) !== null) {
            return $refusal;
        }

        $version = $this->resolveSipgoVersionOrError($referencia, $version_id);

        if (is_array($version)) {
            return $version;
        }

        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $query = EventVersionParticipant::query()
            ->where('event_version_id', $version->getId())
            ->notDeleted();

        $total = (int) (clone $query)->count();

        $registrations = $query
            ->with(['participant.people.organizations', 'participant.people.contacts', 'participantType'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $peopleIds = $registrations
            ->map(fn (EventVersionParticipant $registration): ?int => $registration->participant?->people?->getId())
            ->filter()
            ->values()
            ->all();
        $peopleFields = CustomFieldsBatch::forEntities(People::class, $peopleIds, self::PEOPLE_FIELDS);
        $emailTypeId = ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId();

        $rows = $registrations
            ->map(fn (EventVersionParticipant $registration): array => $this->row($registration, $peopleFields, $emailTypeId))
            ->all();

        $versionFields = CustomFieldsBatch::forEntities(EventVersion::class, [$version->getId()])[$version->getId()] ?? [];

        return array_filter([
            'status' => 'success',
            'version_id' => $version->getId(),
            'codigo' => isset($versionFields[CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value])
                ? SipgoReference::codeFor(SipgoRecordKindEnum::VERSION, (int) $versionFields[CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value])
                : null,
            'version' => $version->name,
            'total' => $total,
            'devueltos' => count($rows),
            'inscritos' => $rows,
            'sincronizado_en' => $versionFields[CustomFieldEnum::INTRAS_SYNCED_AT->value] ?? null,
            ...$this->tenantLabel($version),
            'note' => $this->note(
                $total,
                count($rows),
                $rows,
                $versionFields[CustomFieldEnum::INTRAS_SYNCED_AT->value] ?? null
            ),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * The People custom fields a row shows: the SIPGO id, the cargo, and what a person proofreading
     * the list asks about — the certificate names (what gets printed) and the org placement.
     */
    private const array PEOPLE_FIELDS = [
        'INTRAS_PARTICIPANT_ID',
        'position',
        'area',
        'nivel',
        'department',
        'certificado_primer_nombre',
        'certificado_segundo_nombre',
        'certificado_primer_apellido',
        'certificado_segundo_apellido',
    ];

    /**
     * @param array<int, array<string, string>> $peopleFields
     *
     * @return array<string, mixed>
     */
    private function row(EventVersionParticipant $registration, array $peopleFields, int $emailTypeId): array
    {
        $people = $registration->participant?->people;
        $fields = $people === null ? [] : ($peopleFields[$people->getId()] ?? []);
        $metadata = is_array($registration->metadata) ? $registration->metadata : [];
        $legacyId = (int) ($fields[SipgoRecordKindEnum::PARTICIPANT->linkField()->value] ?? 0);

        $certificateName = trim(implode(' ', array_filter([
            $fields['certificado_primer_nombre'] ?? null,
            $fields['certificado_segundo_nombre'] ?? null,
            $fields['certificado_primer_apellido'] ?? null,
            $fields['certificado_segundo_apellido'] ?? null,
        ])));

        return array_filter([
            'pa' => $legacyId > 0 ? SipgoReference::codeFor(SipgoRecordKindEnum::PARTICIPANT, $legacyId) : null,
            'nombre' => $people?->firstname,
            'apellido' => $people?->lastname,
            'nombre_certificado' => $certificateName === '' ? null : $certificateName,
            'empresa' => $metadata['empresa'] ?? $people?->organizations->first()?->name,
            'cargo' => $metadata['posicion'] ?? $fields['position'] ?? null,
            'cargo_de_la_inscripcion' => isset($metadata['posicion']),
            'area' => $fields['area'] ?? null,
            'nivel' => $fields['nivel'] ?? null,
            'departamento' => $fields['department'] ?? null,
            'tipo_inscripcion' => $registration->participantType?->name,
            'email' => $this->firstEmail($people?->contacts, $emailTypeId),
            'cupos' => max(1, (int) ($metadata['reserved_tickets'] ?? 1)),
            'asistio' => $metadata['assisted_event'] ?? $metadata['is_assisting'] ?? null,
            'precio' => (float) $registration->ticket_price,
            'descuento' => (float) $registration->discount,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function firstEmail(?Collection $contacts, int $emailTypeId): ?string
    {
        $email = $contacts
            ?->first(fn ($contact): bool => (int) $contact->contacts_types_id === $emailTypeId)
            ?->value;

        return $email === null ? null : mb_strtolower(trim((string) $email));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function note(
        int $total,
        int $returned,
        array $rows,
        ?string $syncedAt
    ): string {
        $snapshotCount = count(array_filter($rows, static fn (array $row): bool => $row['cargo_de_la_inscripcion'] ?? false));

        $note = $total === 0
            ? 'La versión no tiene inscritos en Kanvas. Si en SIPGO sí los hay, llama a intras_sincronizar con la misma referencia. '
            : ($returned < $total ? sprintf('Mostrando %d de %d; sube limit para ver el resto. ', $returned, $total) : '');

        $note .= $snapshotCount === $returned
            ? 'Empresa y cargo son los de la inscripción. '
            : 'Empresa y cargo vienen de la ficha del ejecutivo (cargo_de_la_inscripcion=false), no de la '
                . 'inscripción en SIPGO; si la persona dice que un cargo no coincide, es por eso. ';

        $note .= 'nombre y apellido son tal cual están guardados (mayúsculas y tildes incluidas) y nombre_certificado '
            . 'es lo que se imprime; úsalos para revisar ortografía. Muestra los códigos PA a la persona; nunca '
            . 'muestres version_id. ';

        $note .= $syncedAt !== null
            ? 'Los datos reflejan SIPGO a las ' . $syncedAt . '.'
            : 'Los datos vienen de la sincronización programada; si la persona necesita lo último de SIPGO o dice que '
                . 'algo no coincide, llama a intras_sincronizar con la misma referencia y vuelve a leer.';

        return $note;
    }
}
