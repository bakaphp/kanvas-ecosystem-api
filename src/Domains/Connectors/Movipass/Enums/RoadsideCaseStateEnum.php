<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

/**
 * The homologated states of spec v6.0 §12. They live on the assistance case, beside the coarser
 * order status the shared roadside flow drives, so Asistencia Regional gets its full state machine
 * without changing the order type every other tenant uses.
 */
enum RoadsideCaseStateEnum: string
{
    case CREATED = 'CREATED';
    case CABIN_REVIEW = 'CABIN_REVIEW';
    case PENDING_ASSIGNMENT = 'PENDING_ASSIGNMENT';
    case TECHNICIAN_ASSIGNED = 'TECHNICIAN_ASSIGNED';
    case EN_ROUTE = 'EN_ROUTE';
    case NEARBY = 'NEARBY';
    case ON_SITE = 'ON_SITE';
    case IN_PROGRESS = 'IN_PROGRESS';
    case ADDITIONAL_PAYMENT_PENDING = 'ADDITIONAL_PAYMENT_PENDING';
    case REASSIGNMENT = 'REASSIGNMENT';
    case INCIDENT = 'INCIDENT';
    case COMPLETED = 'COMPLETED';
    case NOT_RESOLVED = 'NOT_RESOLVED';
    case CANCELLED = 'CANCELLED';

    public function userLabel(): string
    {
        return match ($this) {
            self::CREATED => 'Solicitud recibida',
            self::CABIN_REVIEW => 'Validando tu solicitud',
            self::PENDING_ASSIGNMENT => 'Buscando asistencia',
            self::TECHNICIAN_ASSIGNED => 'Técnico asignado',
            self::EN_ROUTE => 'Técnico en camino',
            self::NEARBY => 'Tu técnico está cerca',
            self::ON_SITE => 'Técnico en el lugar',
            self::IN_PROGRESS => 'Servicio en proceso',
            self::ADDITIONAL_PAYMENT_PENDING => 'Tienes un pago adicional pendiente',
            self::REASSIGNMENT => 'Estamos asignando otro técnico',
            self::INCIDENT => 'Estamos revisando una incidencia',
            self::COMPLETED => 'Servicio finalizado',
            self::NOT_RESOLVED => 'Servicio no completado',
            self::CANCELLED => 'Solicitud cancelada',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::NOT_RESOLVED, self::CANCELLED], true);
    }

    /**
     * A pending charge and an open incident interrupt whatever stage the case was in and hand it
     * back to that stage when they clear, so they are reachable from every active stage.
     */
    public function isInterruption(): bool
    {
        return $this === self::ADDITIONAL_PAYMENT_PENDING || $this === self::INCIDENT;
    }

    public function hasTechnician(): bool
    {
        return in_array($this, [
            self::TECHNICIAN_ASSIGNED,
            self::EN_ROUTE,
            self::NEARBY,
            self::ON_SITE,
            self::IN_PROGRESS,
        ], true);
    }

    /**
     * The shared flow already pushes its own message for assignment, reassignment, charges,
     * incidents and closing; the state machine only speaks for the states it alone knows about,
     * so the user never gets the same event twice.
     */
    public function notifiesUser(): bool
    {
        return in_array($this, [self::CREATED, self::CABIN_REVIEW, self::EN_ROUTE, self::NEARBY], true);
    }

    /**
     * @return array<int, self>
     */
    public function next(): array
    {
        $exits = [self::INCIDENT, self::CANCELLED];

        return match ($this) {
            self::CREATED => [self::CABIN_REVIEW, self::PENDING_ASSIGNMENT, self::CANCELLED],
            self::CABIN_REVIEW => [self::PENDING_ASSIGNMENT, self::TECHNICIAN_ASSIGNED, self::NOT_RESOLVED, self::CANCELLED],
            self::PENDING_ASSIGNMENT => [self::TECHNICIAN_ASSIGNED, self::CABIN_REVIEW, self::NOT_RESOLVED, ...$exits],
            self::TECHNICIAN_ASSIGNED => [self::EN_ROUTE, self::ON_SITE, self::REASSIGNMENT, self::ADDITIONAL_PAYMENT_PENDING, self::NOT_RESOLVED, ...$exits],
            self::EN_ROUTE => [self::NEARBY, self::ON_SITE, self::REASSIGNMENT, self::ADDITIONAL_PAYMENT_PENDING, self::NOT_RESOLVED, ...$exits],
            self::NEARBY => [self::ON_SITE, self::EN_ROUTE, self::REASSIGNMENT, self::ADDITIONAL_PAYMENT_PENDING, self::NOT_RESOLVED, ...$exits],
            self::ON_SITE => [self::IN_PROGRESS, self::REASSIGNMENT, self::ADDITIONAL_PAYMENT_PENDING, self::NOT_RESOLVED, ...$exits],
            self::IN_PROGRESS => [self::COMPLETED, self::NOT_RESOLVED, self::ADDITIONAL_PAYMENT_PENDING, self::REASSIGNMENT, ...$exits],
            self::REASSIGNMENT => [self::PENDING_ASSIGNMENT, self::TECHNICIAN_ASSIGNED, self::NOT_RESOLVED, ...$exits],
            // Leaving an interruption back to the interrupted stage is checked against the stored
            // stage, not listed here.
            self::ADDITIONAL_PAYMENT_PENDING => [self::INCIDENT, self::NOT_RESOLVED, self::CANCELLED],
            self::INCIDENT => [self::REASSIGNMENT, self::PENDING_ASSIGNMENT, self::NOT_RESOLVED, self::CANCELLED],
            self::COMPLETED, self::NOT_RESOLVED, self::CANCELLED => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /**
     * The shared flow moves the order status; this is the state it implies. Null means the status
     * says nothing new about the case (dispatched only confirms the assignment), and a case sent
     * back to request_submitted after a technician left is a reassignment, not a new request.
     */
    public static function forOrderStatus(string $statusSlug, ?self $current): ?self
    {
        return match ($statusSlug) {
            MovipassOrderStatusEnum::AWAITING_OPERATOR->slug() => self::PENDING_ASSIGNMENT,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug() => self::TECHNICIAN_ASSIGNED,
            MovipassOrderStatusEnum::ON_SITE->slug() => self::ON_SITE,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug() => self::IN_PROGRESS,
            MovipassOrderStatusEnum::SERVICE_COMPLETED->slug() => self::COMPLETED,
            MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug() => self::NOT_RESOLVED,
            MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug() => self::CANCELLED,
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug() => $current !== null
                && ($current->hasTechnician() || $current === self::INCIDENT)
                    ? self::REASSIGNMENT
                    : null,
            default => null,
        };
    }
}
