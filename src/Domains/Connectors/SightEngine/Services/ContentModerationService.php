<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SightEngine\Services;

class ContentModerationService
{
    // Models for image and text moderation
    private const IMAGE_MODERATION_MODELS = 'nudity-2.1,weapon,offensive-2.0,face-attributes,gore-2.0,violence';
    private const TEXT_MODERATION_MODELS = 'general,self-harm';

    // Image Moderation Thresholds
    private const NUDITY_THRESHOLD = 1;
    private const WEAPON_THRESHOLD = 1;
    private const GORE_THRESHOLD = 1;
    private const VIOLENCE_THRESHOLD = 1;

    // Text Moderation Thresholds
    private const SEXUAL_THRESHOLD = 1;
    private const DISCRIMINATORY_THRESHOLD = 1;
    private const VIOLENT_THRESHOLD = 1;
    private const INSULTING_THRESHOLD = 1;

    public function scanImage(string $imageUrl): array
    {
        $params = array(
            'url' =>  $imageUrl,
            'models' => self::IMAGE_MODERATION_MODELS,
            'api_user' => config('services.sightengine.api_user'),
            'api_secret' => config('services.sightengine.api_secret')
        );

        $ch = curl_init( config('services.sightengine.image_moderation.api_url') . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return $this->formatImageModerationResults(json_decode($response, true));
    }

    public function scanText(string $text): array
    {
        $params = array(
            'text' => $text,
            'lang' => 'en',
            'models' => self::TEXT_MODERATION_MODELS,
            'mode' => 'ml',
            'api_user' => config('services.sightengine.api_user'),
            'api_secret' => config('services.sightengine.api_secret')
        );
          
        $ch = curl_init(config('services.sightengine.text_moderation.api_url'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);
        curl_close($ch);

        return $this->formatTextModerationResults(json_decode($response, true));
    }

    private function formatImageModerationResults(array $results): array
    {
        $nudityResults = $results['nudity']['sexual_activity'] >= self::NUDITY_THRESHOLD || $results['nudity']['sexual_display'] >= self::NUDITY_THRESHOLD;
        $weaponResults = $results['weapon']['classes']['firearm'] >= self::WEAPON_THRESHOLD;
        $goreResults = $results['gore']['prob'] >= self::GORE_THRESHOLD;
        $violenceResults = $results['violence']['prob'] >= self::VIOLENCE_THRESHOLD;

        return [
            "scan_status" => $results['status'],
            "nudity_results" => $nudityResults,
            "weapon_results" => $weaponResults,
            "gore_results" => $goreResults,
            "violence_results" => $violenceResults
        ];
    }

    private function formatTextModerationResults(array $results): array
    {
        $sexualResults = $results['moderation_classes']['sexual'] >= self::SEXUAL_THRESHOLD;
        $discriminatoryResults = $results['moderation_classes']['discriminatory'] >= self::DISCRIMINATORY_THRESHOLD;
        $violentResults = $results['moderation_classes']['violent'] >= self::VIOLENT_THRESHOLD;
        $insultingResults = $results['moderation_classes']['insulting'] >= self::INSULTING_THRESHOLD;

        return [
            "scan_status" => $results['status'],
            "sexual_results" => $sexualResults,
            "discriminatory_results" => $discriminatoryResults,
            "violent_results" => $violentResults,
            "insulting_results" => $insultingResults
        ];
    }
}
