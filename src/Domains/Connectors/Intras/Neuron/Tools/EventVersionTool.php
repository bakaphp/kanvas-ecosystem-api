<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Baka\Support\Str;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Neuron\Tools\Concerns\ResolvesSipgoVersion;
use Kanvas\Connectors\Intras\Support\CustomFieldsBatch;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Facilitators\Models\EventVersionFacilitator;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The edition behind a SIPGO link or code, in the words SIPGO uses: código VE, evento EV, fechas,
 * estatus, cupos, inscritos. The person pastes what they have open in SIPGO and this answers from the
 * Kanvas row that carries that id; the Kanvas `version_id` rides along only so the other tools can be
 * called next, and the note tells the model not to show it.
 */
#[AgentTool(name: 'Intras Event Version', category: 'reporting')]
class EventVersionTool extends Tool
{
    use HasKanvasContext;
    use ResolvesSipgoVersion;
    use TrackByInputs;

    protected string $name = 'intras_version';

    protected ?string $description = 'Ficha de una versión (edición) de evento a partir de lo que la persona '
        . 'tiene en SIPGO: un enlace de SIPGO, un código "VE7160" o el número de la versión. Devuelve '
        . 'código, evento, fechas, estatus, cupos, inscritos, lugar y facilitadores, más el version_id que '
        . 'necesitan las demás herramientas. Úsala PRIMERO cuando te den un enlace o código de SIPGO; nunca '
        . 'pases ese número a get_event_version.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            ...$this->versionReferenceProperties(),
            ...$this->tenantCompanyProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $referencia = null,
        ?int $version_id = null,
        ?int $tenant_company_id = null
    ): array {
        if (($refusal = $this->narrowReadScope($tenant_company_id)) !== null) {
            return $refusal;
        }

        $version = $this->resolveSipgoVersionOrError($referencia, $version_id);

        if (is_array($version)) {
            return $version;
        }

        $version->load([
            'event.eventType',
            'event.eventClass',
            'event.eventCategory',
            'eventStatus',
            'currency',
            'dates',
        ]);

        $fields = CustomFieldsBatch::forEntities(EventVersion::class, [$version->getId()])[$version->getId()] ?? [];
        $legacyId = (int) ($fields[SipgoRecordKindEnum::VERSION->linkField()->value] ?? 0);
        $reference = $legacyId > 0 ? new SipgoReference(SipgoRecordKindEnum::VERSION, $legacyId) : null;
        $baseUrl = Str::trimToNull((string) $this->app->get(ConfigurationEnum::INTRAS_BASE_URL->value));

        $registered = (int) EventVersionParticipant::query()
            ->where('event_version_id', $version->getId())
            ->notDeleted()
            ->count();
        $capacity = $version->getMaxCapacity();

        return array_filter([
            'status' => 'success',
            'version_id' => $version->getId(),
            'codigo' => $reference?->code(),
            'enlace_sipgo' => $reference === null || $baseUrl === null ? null : $reference->url($baseUrl),
            'nombre' => $version->name,
            'numero_version' => $version->version_number === null ? null : (int) $version->version_number,
            'evento' => $version->event === null ? null : array_filter([
                'codigo' => SipgoReference::codeOf($version->event, SipgoRecordKindEnum::EVENT),
                'nombre' => $version->event->name,
                'tipo' => $version->event->eventType?->name,
                'clase' => $version->event->eventClass?->name,
                'categoria' => $version->event->eventCategory?->name,
            ]),
            'estatus' => $version->eventStatus?->name,
            'fecha_inicio' => $version->start_at?->toDateString(),
            'fecha_fin' => $version->end_at?->toDateString(),
            'fechas' => $version->dates->map(fn (EventVersionDate $date): array => array_filter([
                'fecha' => $date->event_date?->toDateString(),
                'inicio' => $date->start_time,
                'fin' => $date->end_time,
            ]))->all(),
            'lugar' => $fields['lugar'] ?? null,
            'sala' => $fields['sala'] ?? null,
            'ciudad' => $fields['ciudad_evento'] ?? null,
            'pais' => $fields['pais_evento'] ?? null,
            'cupos' => $capacity,
            'inscritos' => $registered,
            'disponibles' => $capacity === null ? null : max(0, $capacity - $registered),
            'precio' => (float) $version->price_per_ticket,
            'moneda' => $version->currency?->code,
            'facilitadores' => $this->facilitatorNames($version),
            'sincronizado_en' => $fields[CustomFieldEnum::INTRAS_SYNCED_AT->value] ?? null,
            ...$this->tenantLabel($version),
            'note' => ($reference === null
                ? 'Esta versión no está vinculada a SIPGO (no tiene código VE). '
                : 'Muestra el código ' . $reference->code() . ' a la persona. ')
                . 'El version_id es interno: úsalo en las demás herramientas (intras_inscritos, '
                . 'get_event_report, event_tracking) pero no lo muestres. '
                . $this->syncedAtNote($fields[CustomFieldEnum::INTRAS_SYNCED_AT->value] ?? null),
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return list<string>
     */
    private function facilitatorNames(EventVersion $version): array
    {
        return EventVersionFacilitator::query()
            ->where('event_version_id', $version->getId())
            ->with('facilitator.people')
            ->get()
            ->map(fn (EventVersionFacilitator $link): ?string => $link->facilitator?->people?->getName())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
