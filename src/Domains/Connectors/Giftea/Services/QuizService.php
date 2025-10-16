<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Giftea\Services;

use App\Models\QuizSession;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Social\Messages\Models\Message;

class QuizService
{
    public function __construct(
        private RecombeeItemService $recombeeService
    ) {}

    /**
     * Procesar respuestas del quiz
     */
    public function processQuizSubmission(Message $answers, ?string $userId = null): array
    {
        // Generar session ID único
        $sessionId = $userId ?? 'guest_' . Str::uuid();

        // 1. Construir filtros desde las respuestas del quiz
        $filters = $this->buildFiltersFromAnswers($answers);

        // 2. Crear un "perfil virtual" del usuario basado en el quiz
        // Esto le dice a Recombee qué tipo de productos le interesan
        $this->createVirtualUserProfile($sessionId, $answers);

        // 3. Obtener recomendaciones DIRECTAMENTE de Recombee
        // Recombee hará el trabajo de filtrar y rankear los productos
        $recommendations = $this->recombeeService->getRecommendations(
            $sessionId,
            $filters,
            20
        );

        // 4. Guardar sesión para referencia
        // $this->saveSession($sessionId, $answers, $recommendations, $userId);

        return [
            'sessionId' => $sessionId,
            'recommendations' => $recommendations,
            'filters' => $filters,
            'totalResults' => count($recommendations)
        ];
    }

    /**
     * Crear perfil virtual del usuario en Recombee
     * Esto ayuda a Recombee a entender las preferencias sin hacer filtrado local
     */
    private function createVirtualUserProfile(string $sessionId, Message $message): void
    {
        $answers = $message->message;
        // Establecer propiedades del usuario virtual en Recombee
        $this->recombeeService->setUserProperties($sessionId, [
            'recipient_type' => $answers['recipient'],
            'target_age' => $answers['age'],
            'occasion' => $answers['occasion'],
            'interests' => $answers['interests'],
            'personality' => $answers['personality'],
            'budget' => $answers['budget']
        ]);
    }

    /**
     * Refinar recomendaciones con filtros adicionales
     */
    public function refineRecommendations(string $sessionId, array $additionalFilters, int $limit = 20): array
    {
        $cachedSession = Cache::get("quiz_session:{$sessionId}");
        
        if (!$cachedSession) {
            throw new Exception('Session not found or expired');
        }

        $filters = array_merge(
            $cachedSession['filters'],
            $additionalFilters
        );

        // Todo el filtrado y ranking lo hace Recombee
        return $this->recombeeService->getRecommendations($sessionId, $filters, $limit);
    }

    /**
     * Construir filtros para Recombee (NO para DB local)
     */
    private function buildFiltersFromAnswers(Message $answers): array
    {
        $filters = [];

        // Presupuesto
        if (isset($answers['budget'])) {
            $filters['priceRange'] = $this->parseBudget($answers['budget']);
        }

        // Ocasión
        if (isset($answers['occasion'])) {
            $filters['occasion'] = $answers['occasion'];
        }

        // Rango de edad
        if (isset($answers['age'])) {
            $filters['ageRange'] = $answers['age'];
        }

        // Tags preferidos (intereses)
        if (isset($answers['interests'])) {
            $filters['preferredTags'] = $answers['interests'];
        }

        // Categorías basadas en intereses
        if (isset($answers['interests'])) {
            $filters['categories'] = $this->mapInterestsToCategories($answers['interests']);
        }

        // Personalidad
        if (isset($answers['personality'])) {
            $filters['personality'] = $answers['personality'];
        }

        return $filters;
    }

    private function parseBudget(string $budget): array
    {
        $parts = explode('-', $budget);
        
        if (count($parts) === 2) {
            return [(int) $parts[0], (int) $parts[1]];
        }
        
        if (str_ends_with($budget, '+')) {
            $min = (int) rtrim($budget, '+');
            return [$min, 9999];
        }

        return [0, 9999];
    }

    private function mapInterestsToCategories(array $interests): array
    {
        $mapping = [
            'música' => ['audio', 'instrumentos', 'tecnología'],
            'deportes' => ['deportes', 'fitness', 'outdoor', 'activewear'],
            'tecnología' => ['tecnología', 'gadgets', 'electrónica', 'smart-home'],
            'lectura' => ['libros', 'e-readers', 'papelería'],
            'cocina' => ['cocina', 'hogar', 'gourmet', 'electrodomésticos'],
            'arte' => ['arte', 'manualidades', 'decoración'],
            'viajes' => ['viajes', 'outdoor', 'accesorios-viaje']
        ];

        $categories = [];
        foreach ($interests as $interest) {
            if (isset($mapping[$interest])) {
                $categories = array_merge($categories, $mapping[$interest]);
            }
        }

        return array_unique($categories);
    }

    private function saveSession(string $sessionId, array $answers, array $recommendations, ?string $userId): void
    {
        QuizSession::create([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'answers' => $answers,
            'recommendations' => $recommendations,
            'completed_at' => now()
        ]);
    }

    public function getQuestions(): array
    {
        return [
            [
                'id' => 1,
                'question' => '¿Para quién es el regalo?',
                'type' => 'single',
                'required' => true,
                'options' => [
                    ['value' => 'pareja', 'label' => 'Mi pareja'],
                    ['value' => 'amigo', 'label' => 'Un amigo/a'],
                    ['value' => 'familiar', 'label' => 'Un familiar'],
                    ['value' => 'compañero', 'label' => 'Compañero de trabajo'],
                    ['value' => 'otro', 'label' => 'Otra persona']
                ]
            ],
            [
                'id' => 2,
                'question' => '¿Qué edad tiene aproximadamente?',
                'type' => 'single',
                'required' => true,
                'options' => [
                    ['value' => '0-17', 'label' => 'Menor de 18'],
                    ['value' => '18-25', 'label' => '18-25 años'],
                    ['value' => '26-35', 'label' => '26-35 años'],
                    ['value' => '36-50', 'label' => '36-50 años'],
                    ['value' => '50+', 'label' => 'Más de 50 años']
                ]
            ],
            [
                'id' => 3,
                'question' => '¿Cuál es la ocasión?',
                'type' => 'single',
                'required' => true,
                'options' => [
                    ['value' => 'cumpleaños', 'label' => 'Cumpleaños'],
                    ['value' => 'aniversario', 'label' => 'Aniversario'],
                    ['value' => 'navidad', 'label' => 'Navidad'],
                    ['value' => 'graduacion', 'label' => 'Graduación'],
                    ['value' => 'agradecimiento', 'label' => 'Agradecimiento'],
                    ['value' => 'sin-ocasion', 'label' => 'Sin ocasión especial']
                ]
            ],
            [
                'id' => 4,
                'question' => '¿Cuáles son sus principales intereses? (Selecciona hasta 3)',
                'type' => 'multiple',
                'required' => true,
                'maxSelections' => 3,
                'options' => [
                    ['value' => 'música', 'label' => 'Música'],
                    ['value' => 'deportes', 'label' => 'Deportes'],
                    ['value' => 'tecnología', 'label' => 'Tecnología'],
                    ['value' => 'lectura', 'label' => 'Lectura'],
                    ['value' => 'cocina', 'label' => 'Cocina'],
                    ['value' => 'arte', 'label' => 'Arte'],
                    ['value' => 'viajes', 'label' => 'Viajes'],
                    ['value' => 'moda', 'label' => 'Moda'],
                    ['value' => 'gaming', 'label' => 'Videojuegos']
                ]
            ],
            [
                'id' => 5,
                'question' => '¿Cómo describirías su personalidad?',
                'type' => 'single',
                'required' => true,
                'options' => [
                    ['value' => 'practico', 'label' => 'Práctico/a'],
                    ['value' => 'creativo', 'label' => 'Creativo/a'],
                    ['value' => 'aventurero', 'label' => 'Aventurero/a'],
                    ['value' => 'hogareño', 'label' => 'Hogareño/a'],
                    ['value' => 'elegante', 'label' => 'Elegante'],
                    ['value' => 'divertido', 'label' => 'Divertido/a']
                ]
            ],
            [
                'id' => 6,
                'question' => '¿Cuál es tu presupuesto aproximado?',
                'type' => 'single',
                'required' => true,
                'options' => [
                    ['value' => '0-25', 'label' => 'Menos de $25'],
                    ['value' => '25-50', 'label' => '$25 - $50'],
                    ['value' => '50-100', 'label' => '$50 - $100'],
                    ['value' => '100-200', 'label' => '$100 - $200'],
                    ['value' => '200+', 'label' => 'Más de $200']
                ]
            ]
        ];
    }
}