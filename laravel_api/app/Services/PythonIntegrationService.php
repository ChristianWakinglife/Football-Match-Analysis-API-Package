<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PythonIntegrationService
{
    protected string $pythonApiUrl;
    
    public function __construct()
    {
        $this->pythonApiUrl = config('services.python.api_url', 'http://localhost:5000');
    }
    
    public function processPythonRequest(array $mlData): array
    {
        try {
            Log::info('Sending request to Python ML engine', [
                'url' => $this->pythonApiUrl . '/predict',
                'data_size' => strlen(json_encode($mlData))
            ]);
            
            $response = Http::timeout(60)
                ->retry(3, 1000)
                ->post($this->pythonApiUrl . '/predict', $mlData);
                
            if (!$response->successful()) {
                throw new \Exception('Python API request failed: ' . $response->status());
            }
            
            $result = $response->json();
            
            Log::info('Python ML engine response', [
                'has_predictions' => !empty($result['predictions']),
                'prediction_count' => count($result['predictions'] ?? [])
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Python integration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return fallback data or re-throw
            throw $e;
        }
    }
}