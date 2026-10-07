<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionProviderInterface;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Override;

/**
 * The Intras connector's report models.
 *
 * Enablement is read from the connector's own configuration — the same setting `Intras\Client`
 * needs to open the legacy database. An app without it is not running this connector, so it gets
 * no definitions and none of its models are discoverable.
 *
 * Deliberately not keyed off the `integrations` table: nothing seeds an Intras row there today,
 * so that check would be untestable and would return empty for the one app that actually has the
 * connector. When integration rows exist for connectors, this method is the single place to
 * change.
 */
class IntrasReportDefinitionProvider implements ReportDefinitionProviderInterface
{
    /**
     * Order matters: a definition that reads a flattened table must come after the one that
     * builds it. `facilitador_asignacion` reads `evento_version` and `facilitador`;
     * `empresa_plan` and `empresa_oficina` read `empresa`; `cortesia` reads `ejecutivo`.
     *
     * @var array<int, class-string>
     */
    private const array DEFINITIONS = [
        EjecutivoDefinition::class,
        InscripcionDefinition::class,
        EmpresaDefinition::class,
        EmpresaOficinaDefinition::class,
        EmpresaPlanDefinition::class,
        EventoVersionDefinition::class,
        FacilitadorDefinition::class,
        FacilitadorAsignacionDefinition::class,
        CortesiaDefinition::class,
        // Reads the flattened `empresa` and `ejecutivo`, so it comes after both.
        CotizacionDefinition::class,
        // Reads the legacy database directly rather than Kanvas — see the class docblock.
        EvaluacionDefinition::class,
    ];

    /**
     * @return array<int, \Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface>
     */
    #[Override]
    public function definitionsFor(AppInterface $app): array
    {
        if (! $this->isEnabled($app)) {
            return [];
        }

        return array_map(
            fn (string $class) => new $class($app->getId()),
            self::DEFINITIONS
        );
    }

    /**
     * The connector is configured when it can reach the legacy database — the same two settings
     * `Intras\Client` refuses to start without.
     */
    protected function isEnabled(AppInterface $app): bool
    {
        return ! empty($app->get(ConfigurationEnum::INTRAS_DB_HOST->value))
            && ! empty($app->get(ConfigurationEnum::INTRAS_DB_DATABASE->value));
    }
}
