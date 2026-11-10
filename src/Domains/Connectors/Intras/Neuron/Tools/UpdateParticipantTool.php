<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Baka\Support\Str;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Connectors\Intras\Support\SipgoCatalog;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\UpdatePeopleProfileAction;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Tools\Traits\AnnouncesAgentEdits;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Edit a SIPGO participant in SIPGO's own words, so the change actually reaches SIPGO.
 *
 * The generic People tools can edit the same row, but only the fields `ParticipantMapper::toIntras()`
 * knows are pushed: a cargo written as `title`, or a custom field under any other name, lands in Kanvas
 * and is silently never sent. This tool takes a closed set of SIPGO-vocabulary parameters and writes
 * exactly the Kanvas fields and contact slots the push reads, validating catalog names (nivel, área,
 * estatus, profesión, tipo de regalo) against SIPGO before saving so an unknown name is refused here,
 * not at approval time.
 *
 * It writes through the normal save path in the agent's own company, so the Intras rules fire
 * (CREATE_CUSTOM_FIELD on each set(), AGENT_UPDATED from the announcement) and open the
 * `approve_people_intras_update` request; approving it is what pushes to SIPGO.
 */
#[AgentTool(name: 'Intras Update Participant', category: 'crm')]
class UpdateParticipantTool extends Tool implements RequiresSystemAgent
{
    use AnnouncesAgentEdits;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesPersonForTool;
    use TrackByInputs;

    protected string $name = 'intras_actualizar_ejecutivo';

    protected ?string $description = 'Actualiza la ficha de un ejecutivo (participante de SIPGO) en Kanvas: cargo, '
        . 'nivel, área, estatus, profesión, tipo de regalo, cédula, nombre y apellido, nombres del certificado, '
        . 'sexo, email de oficina, email personal, celular y teléfono de oficina. Identifícalo por referencia '
        . '("PA80239" o un enlace de SIPGO) o por el person_id de intras_inscritos/get_person. Cambia SOLO lo '
        . 'que te pidan. El cambio queda PENDIENTE DE APROBACIÓN y llega a SIPGO cuando una persona lo aprueba: '
        . 'dilo siempre. Úsala en vez de update_person o set_person_custom_fields para ejecutivos de SIPGO.';

    /** SIPGO word the person uses => Kanvas People custom field the push reads. */
    private const array DIRECT_FIELDS = [
        'cargo' => 'position',
        'cedula' => 'identification',
        'sexo' => 'sexo',
        'certificado_primer_nombre' => 'certificado_primer_nombre',
        'certificado_segundo_nombre' => 'certificado_segundo_nombre',
        'certificado_primer_apellido' => 'certificado_primer_apellido',
        'certificado_segundo_apellido' => 'certificado_segundo_apellido',
    ];

    /** Parameter => the first slot of that kind in ParticipantMapper::CONTACT_FIELD_MAP. */
    private const array CONTACT_SLOTS = [
        'email_oficina' => 'email_oficina',
        'email_personal' => 'email_personal',
        'celular' => 'celular_1',
        'telefono_oficina' => 'telefono_oficina_1',
    ];

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        $text = fn (string $name, string $description): ToolProperty => new ToolProperty(
            name: $name,
            type: PropertyType::STRING,
            description: $description,
            required: false,
        );

        return [
            $text('referencia', 'Código "PA80239" o enlace de SIPGO del ejecutivo. Obligatorio si no das person_id.'),
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'El person_id que devolvió intras_inscritos o get_person. Alternativa a referencia.',
                required: false,
            ),
            $text('nombre', 'Nuevo nombre (first name), tal como debe quedar en SIPGO.'),
            $text('apellido', 'Nuevo apellido, tal como debe quedar en SIPGO.'),
            $text('cargo', 'Posición / cargo del ejecutivo en su empresa.'),
            $text('cedula', 'Cédula o identificación.'),
            $text('nivel', 'Nivel jerárquico. Debe ser un nombre exacto del catálogo de SIPGO (p. ej. "Gerente").'),
            $text('area', 'Área funcional. Nombre exacto del catálogo de SIPGO.'),
            $text('estatus', 'Estatus del ejecutivo. Nombre exacto del catálogo de SIPGO (p. ej. "Activo").'),
            $text('profesion', 'Profesión. Nombre exacto del catálogo de SIPGO.'),
            $text('tipo_regalo', 'Tipo de regalo. Nombre exacto del catálogo de SIPGO.'),
            $text('sexo', 'Sexo, como lo escribe SIPGO (Masculino / Femenino).'),
            $text('certificado_primer_nombre', 'Primer nombre para el certificado.'),
            $text('certificado_segundo_nombre', 'Segundo nombre para el certificado.'),
            $text('certificado_primer_apellido', 'Primer apellido para el certificado.'),
            $text('certificado_segundo_apellido', 'Segundo apellido para el certificado.'),
            $text('email_oficina', 'Email de oficina. Reemplaza el actual.'),
            $text('email_personal', 'Email personal. Reemplaza el actual.'),
            $text('celular', 'Celular principal. Reemplaza el actual.'),
            $text('telefono_oficina', 'Teléfono de oficina principal. Reemplaza el actual.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $referencia = null,
        ?int $person_id = null,
        ?string $nombre = null,
        ?string $apellido = null,
        ?string $cargo = null,
        ?string $cedula = null,
        ?string $nivel = null,
        ?string $area = null,
        ?string $estatus = null,
        ?string $profesion = null,
        ?string $tipo_regalo = null,
        ?string $sexo = null,
        ?string $certificado_primer_nombre = null,
        ?string $certificado_segundo_nombre = null,
        ?string $certificado_primer_apellido = null,
        ?string $certificado_segundo_apellido = null,
        ?string $email_oficina = null,
        ?string $email_personal = null,
        ?string $celular = null,
        ?string $telefono_oficina = null
    ): array {
        $requested = array_filter(
            array_map(Str::trimToNull(...), compact(
                'nombre',
                'apellido',
                'cargo',
                'cedula',
                'nivel',
                'area',
                'estatus',
                'profesion',
                'tipo_regalo',
                'sexo',
                'certificado_primer_nombre',
                'certificado_segundo_nombre',
                'certificado_primer_apellido',
                'certificado_segundo_apellido',
                'email_oficina',
                'email_personal',
                'celular',
                'telefono_oficina',
            )),
            static fn (?string $value): bool => $value !== null
        );

        if ($requested === []) {
            return $this->invalidArgs('Indica al menos un campo a cambiar (cargo, nivel, nombre, email_oficina, ...).');
        }

        $person = $this->resolveParticipant($referencia, $person_id);

        if (is_array($person)) {
            return $person;
        }

        $refusal = $this->validateCatalogNames($requested);

        if ($refusal !== null) {
            return $refusal;
        }

        $hadPending = $person->pendingApproval(PeopleIntrasSyncApprovalTypeEnum::UPDATE->value) !== null;
        $pa = SipgoReference::codeOf($person, SipgoRecordKindEnum::PARTICIPANT) ?? ('person ' . $person->getId());

        try {
            $changes = $this->apply($person, $requested);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(sprintf('No pude guardar los cambios de %s: %s', $pa, $e->getMessage()));
        }

        if ($changes === []) {
            return $this->noop(
                ['pa' => $pa, 'person_id' => $person->getId()],
                guidance: 'La ficha ya tenía exactamente esos valores; no se cambió nada ni se abrió ninguna aprobación. Díselo a la persona.'
            );
        }

        $this->announceAgentEdit($person);

        return $this->ok(
            [
                'pa' => $pa,
                'person_id' => $person->getId(),
                'cambios' => $changes,
                'pendiente_aprobacion' => true,
            ],
            guidance: sprintf(
                'Los cambios de %s quedaron en Kanvas y PENDIENTES DE APROBACIÓN; llegarán a SIPGO cuando una persona '
                . 'con permiso los apruebe. Dilo así, con el código %s y cada valor nuevo.%s',
                $pa,
                $pa,
                $hadPending ? ' Había una solicitud anterior pendiente para esta persona; la nueva la reemplaza.' : ''
            )
        );
    }

    /**
     * @return People|array<string, mixed>
     */
    private function resolveParticipant(?string $referencia, ?int $personId): People|array
    {
        $referencia = trim((string) $referencia);

        if ($referencia !== '') {
            $reference = SipgoReference::parse($referencia);

            if ($reference === null || $reference->kind !== SipgoRecordKindEnum::PARTICIPANT) {
                return $this->invalidArgs(sprintf(
                    '"%s" no es un código PA ni un enlace de ejecutivo de SIPGO. Usa "PA80239", el enlace .../participants/80239/..., o person_id.',
                    $referencia
                ));
            }

            /** @var People|null $person */
            $person = $reference->resolve($this->app, $this->company);

            return $person ?? $this->notFound(
                ['referencia' => $reference->code()],
                guidance: sprintf(
                    'No hay ningún ejecutivo %s en %s. Si acaba de crearse en SIPGO, sincronízalo con intras_sincronizar y vuelve a intentar; no reintentes igual.',
                    $reference->code(),
                    $this->company->name
                )
            );
        }

        if ($personId === null || $personId <= 0) {
            return $this->invalidArgs('Indica referencia (código PA o enlace) o person_id.');
        }

        return $this->resolvePersonOrError($personId);
    }

    /**
     * @param array<string, string> $requested
     *
     * @return array<string, mixed>|null a refusal, or null when every catalog value exists
     */
    private function validateCatalogNames(array $requested): ?array
    {
        $tables = array_column(ParticipantMapper::LOOKUP_FIELD_MAP, 'table', 'custom_field');
        $toCheck = array_intersect_key($requested, $tables);

        if ($toCheck === []) {
            return null;
        }

        try {
            $catalog = new SipgoCatalog(new Client($this->app));

            foreach ($toCheck as $field => $value) {
                $catalog->idFor($tables[$field], $value);
            }
        } catch (ValidationException $e) {
            return $this->invalidArgs(sprintf(
                '%s. Usa el nombre exacto tal como aparece en SIPGO y pregunta a la persona si no lo conoces. Nada se cambió.',
                $e->getMessage()
            ));
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'No pude validar nivel/área/estatus contra el catálogo de SIPGO en este momento, así que no guardé esos '
                . 'campos: un nombre que no exista en SIPGO haría fallar la aprobación. Inténtalo más tarde.'
            );
        }

        return null;
    }

    /**
     * @param array<string, string> $requested
     *
     * @return array<string, array{de: string|null, a: string}>
     */
    private function apply(People $person, array $requested): array
    {
        $changes = [];

        $firstname = $requested['nombre'] ?? null;
        $lastname = $requested['apellido'] ?? null;

        if (($firstname !== null && $firstname !== $person->firstname) || ($lastname !== null && $lastname !== $person->lastname)) {
            $before = ['firstname' => $person->firstname, 'lastname' => $person->lastname];

            $person = new UpdatePeopleProfileAction(
                $person,
                $this->app,
                firstname: $firstname,
                lastname: $lastname,
            )->execute();

            if ($firstname !== null && $firstname !== $before['firstname']) {
                $changes['nombre'] = ['de' => $before['firstname'], 'a' => $firstname];
            }

            if ($lastname !== null && $lastname !== $before['lastname']) {
                $changes['apellido'] = ['de' => $before['lastname'], 'a' => $lastname];
            }
        }

        $catalogFields = array_column(ParticipantMapper::LOOKUP_FIELD_MAP, 'custom_field', 'custom_field');
        $customFields = [...self::DIRECT_FIELDS, ...$catalogFields];

        foreach ($customFields as $word => $field) {
            if (! isset($requested[$word])) {
                continue;
            }

            $current = Str::trimToNull((string) $person->get($field));

            if ($current === $requested[$word]) {
                continue;
            }

            $person->set($field, $requested[$word]);
            $changes[$word] = ['de' => $current, 'a' => $requested[$word]];
        }

        foreach (self::CONTACT_SLOTS as $word => $slot) {
            if (! isset($requested[$word])) {
                continue;
            }

            $spec = ParticipantMapper::CONTACT_FIELD_MAP[$slot];
            $previous = $this->replaceContact(
                $person,
                $spec['type'],
                $requested[$word],
                (int) $spec['weight']
            );

            if ($previous !== false) {
                $changes[$word] = ['de' => $previous, 'a' => $requested[$word]];
            }
        }

        return $changes;
    }

    /**
     * The push fills SIPGO's slots from the person's contacts of each type in weight order, so a
     * correction has to retire the contact currently holding the slot rather than add a second one.
     *
     * @return string|null|false the value the slot held, or false when it already held this value
     */
    private function replaceContact(
        People $person,
        ContactTypeEnum $type,
        string $value,
        int $weight
    ): string|null|false {
        $typeId = ContactType::getByName($type->getName())->getId();
        $normalized = Contact::normalizeValue($value, $typeId);

        /** @var Contact|null $current */
        $current = $person->contacts()
            ->where('contacts_types_id', $typeId)
            ->where('weight', $weight)
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->first();

        if ($current !== null && Contact::normalizeValue((string) $current->value, $typeId) === $normalized) {
            return false;
        }

        $current?->delete();
        $person->addContact(
            $type->getName(),
            $value,
            0,
            $weight
        );

        return $current?->value;
    }
}
