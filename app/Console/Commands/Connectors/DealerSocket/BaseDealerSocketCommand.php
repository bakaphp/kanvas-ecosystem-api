<?php

namespace App\Console\Commands\Connectors\DealerSocket;

use Illuminate\Console\Command;
use Kanvas\Connectors\DealerSocket\ActivityClient;
use Kanvas\Connectors\DealerSocket\CustomerClient;
use Kanvas\Connectors\DealerSocket\EventSearchClient;

abstract class BaseDealerSocketCommand extends Command
{
    protected CustomerClient $customerClient;
    protected ActivityClient $activityClient;
    protected EventSearchClient $eventSearchClient;
    
    public function __construct(
        CustomerClient $customerClient,
        ActivityClient $activityClient,
        EventSearchClient $eventSearchClient
    ) {
        parent::__construct();
        $this->customerClient = $customerClient;
        $this->activityClient = $activityClient;
        $this->eventSearchClient = $eventSearchClient;
    }
    
    /**
     * Muestra una respuesta XML formateada
     */
    protected function displayXmlResponse($response, string $title = 'Response')
    {
        $this->info("\n📄 {$title}:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        if (is_object($response)) {
            $success = (string)$response->Success === 'true';
            
            if ($success) {
                $this->info('✅ Success: true');
                
                if (isset($response->EntityId)) {
                    $this->info("👤 Entity ID: {$response->EntityId}");
                }
                if (isset($response->ActivityId)) {
                    $this->info("📋 Activity ID: {$response->ActivityId}");
                }
                if (isset($response->Message)) {
                    $this->line("💬 Message: {$response->Message}");
                }
            } else {
                $this->error('❌ Success: false');
                $this->error("🚫 Error Code: {$response->ErrorCode}");
                $this->error("📝 Error Message: {$response->ErrorMessage}");
            }
        } else {
            $this->warn('⚠️  Respuesta no es un objeto XML válido');
            $this->line(print_r($response, true));
        }
        
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
    
    /**
     * Muestra una respuesta JSON formateada
     */
    protected function displayJsonResponse(array $response, string $title = 'Response')
    {
        $this->info("\n📄 {$title}:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
    
    /**
     * Muestra error formateado
     */
    protected function displayError(\Exception $e)
    {
        $this->error("\n❌ Error:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->error($e->getMessage());
        
        if ($this->option('verbose')) {
            $this->line("\n📍 Stack Trace:");
            $this->line($e->getTraceAsString());
        }
        
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
    
    /**
     * Confirma la acción con el usuario
     */
    protected function confirmAction(string $message): bool
    {
        if ($this->option('force')) {
            return true;
        }
        
        return $this->confirm($message);
    }
    
    /**
     * Muestra configuración actual
     */
    protected function displayConfig()
    {
        $this->info("\n⚙️  Configuración DealerSocket:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("Vendor: " . config('dealersocket.vendor_name'));
        $this->line("Dealer ID: " . config('dealersocket.dealer_id'));
        $this->line("Base URL: " . config('dealersocket.base_url'));
        $this->line("Public Key: " . substr(config('dealersocket.public_key'), 0, 10) . "...");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}