<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

/**
 * Roadside assistance provider REST paths. The provider exposes no webhooks, so anything that moves
 * on their side only reaches us through the ASSISTANCE read. The path strings are theirs and stay
 * verbatim; keeping them here stops the poll job and the sync actions from drifting onto different
 * spellings.
 */
enum RoadsideProviderEndpointEnum: string
{
    case ASSISTANCE = '/api/Asistencia';
    case ASSISTANCE_STATE = '/api/Asistencia/{numeroAsistencia}/Estado';
    case ASSISTANCE_CONTACT = '/api/Asistencia/{numeroAsistencia}/Contacto';

    case CATALOG_STATES = '/api/Asistencia/Estados';
    case CATALOG_CAUSES = '/api/Causa';
    case CATALOG_DEPARTMENTS = '/api/Departamentos';
    case CATALOG_MUNICIPALITIES = '/api/Departamentos/{departamentoId}';
    case CATALOG_PROVIDERS = '/api/Proveedores';
    case CATALOG_ASSISTANCE_TYPES = '/api/TipoAsistencia';

    public function withCaseNumber(string $caseNumber): string
    {
        return str_replace('{numeroAsistencia}', rawurlencode($caseNumber), $this->value);
    }

    public function withDepartment(int|string $departmentId): string
    {
        return str_replace('{departamentoId}', rawurlencode((string) $departmentId), $this->value);
    }
}
