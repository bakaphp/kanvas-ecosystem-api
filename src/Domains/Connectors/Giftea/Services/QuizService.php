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

    public function processQuizSubmission(Message $answers, ?string $userId = null): array
    {
        $sessionId = $userId ?? 'guest_' . Str::uuid();

        $filters = $this->buildFiltersFromAnswers($answers);

        $this->createVirtualUserProfile($sessionId, $answers);

        $recommendations = $this->recombeeService->getRecommendations(
            $sessionId,
            $filters,
            20
        );

        return [
            'sessionId' => $sessionId,
            'recommendations' => $recommendations,
            'filters' => $filters,
            'totalResults' => count($recommendations)
        ];
    }

    private function createVirtualUserProfile(string $sessionId, Message $message): void
    {
        $answers = $message->message;

        $this->recombeeService->setUserProperties($sessionId, [
            'recipient_type' => $answers['recipient'],
            'target_age' => $answers['age'],
            'occasion' => $answers['occasion'],
            'interests' => $answers['interests'],
            'personality' => $answers['personality'],
            'budget' => $answers['budget']
        ]);
    }

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

        return $this->recombeeService->getRecommendations($sessionId, $filters, $limit);
    }

    private function buildFiltersFromAnswers(Message $answers): array
    {
        $filters = [];
        $messageData = $answers->message;

        if (isset($messageData['budget'])) {
            $filters['priceRange'] = $this->parseBudget($messageData['budget']);
        }

        if (isset($messageData['occasion'])) {
            $filters['occasion'] = $messageData['occasion'];
        }

        if (isset($messageData['age'])) {
            $filters['ageRange'] = $messageData['age'];
        }

        if (isset($messageData['interests'])) {
            $filters['preferredTags'] = $messageData['interests'];
        }

        if (isset($messageData['interests'])) {
            $filters['categories'] = $this->mapInterestsToCategories($messageData['interests']);
        }

        if (isset($messageData['personality'])) {
            $filters['personality'] = $messageData['personality'];
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