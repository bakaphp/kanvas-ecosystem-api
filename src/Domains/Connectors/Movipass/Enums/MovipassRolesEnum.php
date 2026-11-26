<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum MovipassRolesEnum: string
{
    case OPERATIONS = 'operaciones';
    case FINANCE = 'FinanzasOperaciones';
    case AGENT = 'agente';
    case TRUCK_DRIVER = 'gruero';
    case ROADSIDE_ASSISTANCE_OPERATOR = 'roadside_assistance_operator';
    case OPERATOR = 'Operador';
    case RDVIAL_CONSULTANT = 'RdvialConsultant';
    case VIEWER = 'viewer';
    case PARQUEAT = 'parqueat';
    case PARKING_MANAGER = 'Parqueos';
    case PARKING_OPERATOR = 'ParkingSales';
    case INSURER = 'Aseguradora';
    case EVENT_VIEWER = 'EventViewer';

    public function description(): string
    {
        return match ($this) {
            self::OPERATIONS => 'Equipo interno de MoviPass: ve y gestiona órdenes, corrige datos, ajusta montos y recarga billeteras.',
            self::FINANCE => 'Equipo interno de finanzas: ve órdenes, descarga reportes y gestiona recargas y reembolsos de billetera.',
            self::AGENT => 'Agente de servicio: ve y actualiza órdenes.',
            self::TRUCK_DRIVER => 'Gruero: ve las órdenes que le tocan y puede descargarlas.',
            self::ROADSIDE_ASSISTANCE_OPERATOR => 'Operador de asistencia vial: atiende los casos de asistencia en carretera.',
            self::OPERATOR => 'Operador del proveedor: trabaja el día a día de las órdenes de su empresa.',
            self::RDVIAL_CONSULTANT => 'Consultor de RD Vial: solo consulta Paso Rápido.',
            self::VIEWER => 'Solo lectura: puede ver la billetera pero no cambiar nada.',
            self::PARQUEAT => 'Personal de Parqueat: ve y actualiza órdenes, corrige placas y reubica vehículos.',
            self::PARKING_MANAGER => 'Administrador de parqueo: gestiona órdenes, cancela, ajusta montos y configura su empresa.',
            self::PARKING_OPERATOR => 'Operador de parqueo (ventas): registra y actualiza órdenes, observaciones y pagos.',
            self::INSURER => 'Aseguradora: ve órdenes y reportes de seguros.',
            self::EVENT_VIEWER => 'Solo lectura de eventos: consulta estadísticas de invitaciones, ocupación y la lista de reservas, sin cambiar nada.',
        };
    }
}
