<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

/**
 * The taxonomy INTRAS specified. It reaches the classifier as the people_classification_taxonomy app
 * setting, so this is only what the setup command writes there.
 */
final class IntrasPeopleTaxonomy
{
    public const array VALUE = [
        'levels' => [
            'C_LEVEL' => [
                'label' => 'C-Level / Alta Dirección',
                'tag' => 'Nivel: C-Level',
                'keywords' => ['CEO', 'CFO', 'COO', 'CIO', 'CTO', 'CMO', 'CHRO', 'Presidente', 'Vicepresidente', 'VP', 'Executive Director', 'Socio', 'Partner', 'Fundador', 'Founder', 'Director General'],
            ],
            'DIRECTOR' => [
                'label' => 'Dirección',
                'tag' => 'Nivel: Dirección',
                'keywords' => ['Director', 'Directora', 'Head of', 'Lead Director'],
            ],
            'GERENTE' => [
                'label' => 'Gerencia',
                'tag' => 'Nivel: Gerencia',
                'keywords' => ['Gerente', 'Manager', 'Subgerente', 'Country Manager', 'Business Unit Leader'],
            ],
            'JEFATURA' => [
                'label' => 'Jefatura / Coordinación',
                'tag' => 'Nivel: Jefatura',
                'keywords' => ['Jefe', 'Jefa', 'Coordinador', 'Coordinadora', 'Supervisor', 'Team Lead', 'Team Leader'],
            ],
            'ESPECIALISTA' => [
                'label' => 'Especialista / Operativo',
                'tag' => 'Nivel: Especialista',
                'keywords' => ['Analista', 'Especialista', 'Consultor', 'Ingeniero', 'Ejecutivo', 'Asistente', 'Oficial', 'Auxiliar'],
            ],
        ],
        'areas' => [
            'RRHH' => [
                'label' => 'Gestión Humana / RRHH',
                'tag' => 'Área: RRHH',
                'keywords' => ['Recursos Humanos', 'Gestión Humana', 'Talento', 'People', 'HR', 'Nómina', 'Compensación', 'Cultura', 'Capacitación'],
            ],
            'FINANZAS' => [
                'label' => 'Finanzas & Contabilidad',
                'tag' => 'Área: Finanzas',
                'keywords' => ['Finanzas', 'Tesorería', 'Contabilidad', 'Auditoría', 'Contralor', 'Controller', 'Riesgo', 'Cobros', 'AR', 'AP', 'Créditos'],
            ],
            'TECNOLOGIA' => [
                'label' => 'Tecnología & Ciberseguridad',
                'tag' => 'Área: Tecnología',
                'keywords' => ['IT', 'TI', 'Sistemas', 'Tecnología', 'Ciberseguridad', 'Seguridad de la Información', 'Infraestructura', 'Software', 'DevOps', 'Datos', 'Data'],
            ],
            'OPERACIONES' => [
                'label' => 'Operaciones & Logística',
                'tag' => 'Área: Operaciones',
                'keywords' => ['Operaciones', 'Cadena de Suministro', 'Supply Chain', 'Logística', 'Almacén', 'Producción', 'Compras', 'Procurement', 'Calidad'],
            ],
            'MKT_VENTAS' => [
                'label' => 'Mercadeo, Ventas & Comercial',
                'tag' => 'Área: Mercadeo y Ventas',
                'keywords' => ['Mercadeo', 'Marketing', 'Ventas', 'Comercial', 'Business Development', 'Cuentas', 'Trade', 'Publicidad', 'Growth'],
            ],
            'LEGAL_CUMP' => [
                'label' => 'Legal & Cumplimiento',
                'tag' => 'Área: Legal',
                'keywords' => ['Legal', 'Jurídico', 'Cumplimiento', 'Compliance', 'Abogado', 'Asuntos Regulatorios', 'Prevención de Lavado'],
            ],
            'DIRECCION' => [
                'label' => 'Dirección General & Estrategia',
                'tag' => 'Área: Dirección General',
                'keywords' => ['Dirección General', 'Presidencia', 'Estrategia', 'Planificación', 'Nuevos Negocios', 'Transformación'],
            ],
        ],
    ];
}
