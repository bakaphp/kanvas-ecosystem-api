<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools\Concerns;

use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Event\Events\Models\EventVersion;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The two ways an Intras tool is told which edition to read: a SIPGO reference the person pasted
 * (link, `VE7160`, or a bare number), or the Kanvas `version_id` another tool already returned. Both
 * resolve inside the audited read scope and answer a structured error instead of throwing, so a wrong
 * paste or a hallucinated id talks back to the model rather than killing the turn.
 */
trait ResolvesSipgoVersion
{
    /**
     * @return list<ToolProperty>
     */
    protected function versionReferenceProperties(): array
    {
        return [
            new ToolProperty(
                name: 'referencia',
                type: PropertyType::STRING,
                description: 'Lo que la persona pegó: un enlace de SIPGO (termina en '
                    . '/events-versions/7160/participants o /events-versions/7160/edit), un código de versión '
                    . '("VE7160") o el número de la versión en SIPGO ("7160"). Úsala siempre que la '
                    . 'persona hable en términos de SIPGO.',
                required: false,
            ),
            new ToolProperty(
                name: 'version_id',
                type: PropertyType::INTEGER,
                description: 'El version_id interno que devolvió otra herramienta (intras_event_search, '
                    . 'get_calendar). NUNCA pongas aquí un número tomado de un enlace o código de SIPGO; '
                    . 'eso va en referencia.',
                required: false,
            ),
        ];
    }

    /**
     * @return EventVersion|array{status: string, message: string}
     */
    protected function resolveSipgoVersionOrError(?string $referencia, ?int $versionId): EventVersion|array
    {
        $referencia = trim((string) $referencia);
        $scope = $this->auditedReadScope();

        if ($referencia !== '') {
            $reference = SipgoReference::parse($referencia);

            if ($reference === null) {
                return $this->error(sprintf(
                    'No reconozco "%s" como enlace ni código de SIPGO. Sirven: un enlace de SIPGO, '
                    . 'un código como VE7160, o el número de la versión. No reintentes con la misma referencia.',
                    $referencia
                ));
            }

            if ($reference->kind !== SipgoRecordKindEnum::VERSION) {
                return $this->error(sprintf(
                    '%s es un(a) %s de SIPGO, no una versión de evento. %s',
                    $reference->code(),
                    $reference->kind->label(),
                    $reference->kind === SipgoRecordKindEnum::EVENT
                        ? 'Para ver sus versiones usa intras_event_search(codigo: "' . $reference->code() . '").'
                        : 'Pide a la persona el enlace o el código VE de la versión.'
                ));
            }

            /** @var EventVersion|null $version */
            $version = $reference->resolve($this->app, $scope);

            if ($version === null) {
                return $this->error(sprintf(
                    'No hay ninguna versión %s en %s. Si la crearon en SIPGO hace poco puede que aún no '
                    . 'esté sincronizada. No reintentes con la misma referencia: dile a la persona qué buscaste.',
                    $reference->code(),
                    $scope->describeCompanies()
                ));
            }

            return $version;
        }

        if ($versionId === null || $versionId <= 0) {
            return $this->error('Indica referencia (enlace o código de SIPGO) o version_id.');
        }

        /** @var EventVersion|null $version */
        $version = EventVersion::query()
            ->where('id', $versionId)
            ->fromApp($this->app)
            ->fromCompany($scope)
            ->notDeleted()
            ->first();

        if ($version === null) {
            return $this->error(sprintf(
                'No hay ninguna versión con version_id %d en %s. Si el número viene de un enlace o código '
                . 'de SIPGO, pásalo en referencia, no en version_id.',
                $versionId,
                $scope->describeCompanies()
            ));
        }

        return $version;
    }

    /**
     * @return array{status: string, message: string}
     */
    private function error(string $message): array
    {
        return ['status' => 'error', 'message' => $message];
    }
}
