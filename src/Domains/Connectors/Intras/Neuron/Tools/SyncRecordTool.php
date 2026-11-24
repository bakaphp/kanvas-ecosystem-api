<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Connectors\Intras\Actions\SyncSipgoRecordAction;
use Kanvas\Connectors\Intras\Jobs\SyncSipgoRecordJob;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * "Tráeme lo que está en SIPGO ahora mismo." Refreshes one record's graph from SIPGO into Kanvas so
 * the read tools answer with current names, positions and inscriptions instead of the last scheduled
 * import. Internal agents only: it writes Kanvas rows for the agent's own company.
 */
#[AgentTool(name: 'Intras Sync Record', category: 'reporting')]
class SyncRecordTool extends Tool implements RequiresSystemAgent
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'intras_sincronizar';

    protected ?string $description = 'Actualiza desde SIPGO, en este momento, una versión (enlace o "VE7160"), un '
        . 'evento ("EV3576"), un ejecutivo ("PA80239") o una empresa ("EM11608") con todo lo que depende de '
        . 'ellos: ejecutivos, empresas, fechas e inscripciones. Úsala cuando la persona diga que un dato no '
        . 'coincide con SIPGO, pida "lo más reciente", o cuando sincronizado_en sea viejo. Después vuelve a '
        . 'llamar a intras_version o intras_inscritos. Si el registro es grande lo deja en cola y te lo dice.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'referencia',
                type: PropertyType::STRING,
                description: 'Enlace de SIPGO, o código VE/EV/PA/EM. Un número solo se toma como versión.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $referencia): array
    {
        $referencia = trim($referencia);

        return $this->oncePerTurn(
            ['referencia' => $referencia],
            fn (): array => $this->sync($referencia),
            note: 'Ya sincronizaste esta referencia en este turno; este es el mismo resultado. Llama a intras_version o intras_inscritos.',
            rememberFailures: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(string $referencia): array
    {
        $reference = SipgoReference::parse($referencia);

        if ($reference === null) {
            return $this->failure(sprintf(
                'No reconozco "%s" como enlace ni código de SIPGO. Sirven: un enlace de SIPGO, VE7160, EV3576, PA80239 o EM11608.',
                $referencia
            ));
        }

        $action = new SyncSipgoRecordAction(
            $this->app,
            $this->company,
            $this->user,
            $reference
        );

        try {
            $plan = $action->plan();
        } catch (ValidationException $e) {
            return $this->failure($e->getMessage() . ' No reintentes con la misma referencia.');
        } catch (Throwable $e) {
            report($e);

            return $this->failure('No pude leer SIPGO para planificar la sincronización: ' . $e->getMessage());
        }

        if ($plan->rows() > SyncSipgoRecordAction::INLINE_ROW_LIMIT) {
            SyncSipgoRecordJob::dispatch(
                $this->app,
                $this->company,
                $this->user,
                $reference->kind->value,
                $reference->legacyId
            );

            return [
                'status' => 'queued',
                'success' => true,
                'referencia' => $reference->code(),
                'filas' => $plan->rows(),
                'plan' => $plan->summary(),
                'note' => sprintf(
                    'Son %d filas, demasiadas para hacerlo en esta respuesta: la sincronización de %s quedó en cola y tarda '
                    . 'unos minutos. Dile a la persona que vuelva a preguntar en un momento; no la vuelvas a encolar.',
                    $plan->rows(),
                    $reference->code()
                ),
            ];
        }

        try {
            $result = $action->run($plan);
        } catch (Throwable $e) {
            report($e);

            return $this->failure(sprintf('La sincronización de %s falló: %s', $reference->code(), $e->getMessage()));
        }

        $skipped = $result['omitidos_por_aprobacion'];

        return [
            'status' => 'success',
            'success' => true,
            'referencia' => $reference->code(),
            ...$result,
            'note' => sprintf(
                'Kanvas refleja SIPGO a las %s para %s. Ahora llama a intras_version o intras_inscritos con la misma referencia '
                . 'para leer los datos actualizados.%s',
                $result['sincronizado_en'],
                $reference->code(),
                $skipped === [] ? '' : ' No se actualizaron ' . implode(', ', $skipped)
                    . ' porque tienen cambios hechos en Kanvas pendientes de aprobación; dilo si preguntan por ellos.'
            ),
        ];
    }

    /**
     * @return array{status: string, success: bool, message: string}
     */
    private function failure(string $message): array
    {
        return ['status' => 'error', 'success' => false, 'message' => $message];
    }
}
