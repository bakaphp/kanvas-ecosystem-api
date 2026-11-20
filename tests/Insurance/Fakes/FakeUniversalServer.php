<?php

declare(strict_types=1);

namespace Tests\Insurance\Fakes;

use Kanvas\Connectors\UniversalSeguros\DataTransferObject\PaymentInformation;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentOperationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentTransactionEnum;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;

class FakeUniversalServer extends UniversalSegurosService
{
    public const string POLICY = 'A-PA-0000001392';

    public const string POLICY_STARTS = '2026-10-23';

    public const string POLICY_ENDS = '2027-10-23';

    private const array REQUIRED_TERMS = [
        'seguroLey',
        'asistenciaVehicular',
        'autoSustituto',
        'fraccionamientoPago',
        'formaPago',
    ];

    private const array ACCEPTED_TRANSACTIONS = ['Matricula', 'Inspeccion', 'Pasaporte', 'Conduce'];

    public array $quotes = [];

    public array $documents = [];

    public array $payments = [];

    public array $invoices = [];

    public array $calls = [];

    public bool $refuseEmission = false;

    public const array PERIODS = [
        'A' => [5239.67, 838.33, 6078.0],
        'S' => [2720.0, 435.2, 3155.2],
        'T' => [1310.0, 209.6, 1519.6],
        'M' => [483.0, 77.28, 560.28],
        'PU' => [5239.67, 838.33, 6078.0],
    ];

    private int $sequence = 355000;

    public function __construct()
    {
    }

    public function quote(QuoteRequest $request): array
    {
        $this->calls[] = 'quote';
        $data = $request->toArray()['data'];

        $this->assertVehicle($data);
        $this->assertTerms($data);

        $requestId = (string) ($data['requestId'] ?? '');

        if ($requestId !== '' && $this->requestIdTaken($requestId)) {
            throw new ValidationException('Ha ocurrido un error desconocido');
        }

        $number = (string) ++$this->sequence;
        $this->quotes[$number] = [
            'producto' => $request->producto,
            'data' => $data + ['requestId' => null],
            'paid' => false,
            'policy' => null,
        ];

        return $this->present($number);
    }

    public function getQuote(string $numeroCotizacion): array
    {
        $this->calls[] = 'getQuote';

        return $this->present($this->existing($numeroCotizacion));
    }

    public function updateQuote(string $numeroCotizacion, QuoteRequest $request): array
    {
        $this->calls[] = 'updateQuote';
        $stored = $this->existing($numeroCotizacion);
        $data = $request->toArray()['data'];

        $this->assertVehicle($data);
        $this->assertTerms($data);

        $this->quotes[$stored]['data'] = array_merge(
            ['cliente' => null],
            $data,
            ['requestId' => $this->quotes[$stored]['data']['requestId'] ?? null]
        );

        return $this->present($stored);
    }

    public function uploadDocument(
        string $referencia,
        DocumentTransactionEnum $transaction,
        DocumentOperationEnum $operation,
        string $filePath
    ): array {
        $this->calls[] = 'uploadDocument';

        if (! in_array($transaction->value, self::ACCEPTED_TRANSACTIONS, true)) {
            throw new ValidationException('Tipo de transaccion no valido');
        }

        $this->existing($referencia);
        $this->documents[$referencia][] = $transaction->value;

        return ['url' => ''];
    }

    public function assignPaymentInformation(PaymentInformation $payment): array
    {
        $this->calls[] = 'assignPaymentInformation';
        $number = $this->existing((string) $payment->numeroCotizacion);

        $this->quotes[$number]['paid'] = true;
        $this->payments[$number] = $payment->toArray();

        return [];
    }

    public function emit(string $numeroCotizacion): array
    {
        $this->calls[] = 'emit';
        $number = $this->existing($numeroCotizacion);
        $data = $this->quotes[$number]['data'];

        if (($data['requestId'] ?? '') === '') {
            throw new ValidationException("Data.RequestId: 'Data Request Id' no debería estar vacío.");
        }

        if (empty($data['cliente'])) {
            throw new ValidationException("Data.Cliente: 'Data Cliente' no debería estar vacío.");
        }

        $hasChassis = ($data['vehiculo']['inspeccion']['chasis'] ?? '') !== '';
        $hasRegistration = in_array('Matricula', $this->documents[$number] ?? [], true);

        if ($this->refuseEmission || ! $this->quotes[$number]['paid'] || ! $hasChassis || ! $hasRegistration) {
            throw new ValidationException('Server error: 500 Internal Server Error');
        }

        $this->quotes[$number]['policy'] = self::POLICY;

        return [];
    }

    public function getPolicy(string $numeroCotizacion): array
    {
        $policy = $this->existing($numeroCotizacion);

        if ($this->quotes[$policy]['policy'] === null) {
            return [];
        }

        return [
            'producto' => 'A-PA',
            'data' => [
                'estado' => 'ACT',
                'numeroCotizacion' => $numeroCotizacion,
                'numeroPoliza' => $this->quotes[$policy]['policy'],
                'terminos' => [
                    'inicioVigencia' => self::POLICY_STARTS . 'T00:00:00',
                    'finVigencia' => self::POLICY_ENDS . 'T00:00:00',
                ],
                'pago' => ['pagado' => true],
            ],
        ];
    }

    public function invoicePolicy(PaymentInformation $payment): array
    {
        $this->calls[] = 'invoicePolicy';
        $this->invoices[] = $payment->toArray();

        return [];
    }

    public function callCount(string $name): int
    {
        return count(array_keys($this->calls, $name, true));
    }

    private function present(string $number): array
    {
        $quote = $this->quotes[$number];
        $data = $quote['data'];
        $data['numeroCotizacion'] = $number;
        $data['producto'] = $quote['producto'];
        $data['pago'] = ['pagado' => $quote['paid']];
        $periods = self::PERIODS;
        $selected = $periods[$data['terminos']['fraccionamientoPago'] ?? 'A'] ?? $periods['A'];
        $data['terminos'] = ($data['terminos'] ?? []) + ['prima' => $selected[0], 'impuesto' => $selected[1], 'totalCobro' => $selected[2]];
        $data['primas'] = array_map(
            fn (string $code, array $period): array => ['fraccionamientoPago' => $code, 'prima' => $period[0], 'impuesto' => $period[1], 'cobro' => $period[2]],
            array_keys($periods),
            $periods
        );

        return ['numeroCotizacion' => $number, 'data' => $data];
    }

    private function existing(string $number): string
    {
        if (! isset($this->quotes[$number])) {
            throw new ValidationException('Cotizacion no encontrada');
        }

        return $number;
    }

    private function requestIdTaken(string $requestId): bool
    {
        foreach ($this->quotes as $quote) {
            if (($quote['data']['requestId'] ?? null) === $requestId) {
                return true;
            }
        }

        return false;
    }

    private function assertVehicle(array $data): void
    {
        foreach (['idModelo', 'anio', 'combustible', 'valor'] as $field) {
            if (empty($data['vehiculo'][$field] ?? null)) {
                throw new ValidationException("Data.Vehiculo.{$field} no debería estar vacío.");
            }
        }
    }

    private function assertTerms(array $data): void
    {
        foreach (self::REQUIRED_TERMS as $field) {
            if (! array_key_exists($field, $data['terminos'] ?? [])) {
                throw new ValidationException("Data.Terminos.{$field} no debería estar vacío.");
            }
        }
    }
}
