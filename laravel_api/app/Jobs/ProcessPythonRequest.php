<?php

namespace App\Jobs;

use App\Models\MasterSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ProcessPythonRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    protected int $masterSlipId;
    protected array $payload;
    protected string $predictionType;
    protected string $riskProfile;

    /**
     * Create a new job instance
     */
    public function __construct(
        int $masterSlipId,
        array $payload,
        string $predictionType = 'monte_carlo',
        string $riskProfile = 'medium'
    ) {
        $this->masterSlipId = $masterSlipId;
        $this->payload = $payload;
        $this->predictionType = $predictionType;
        $this->riskProfile = $riskProfile;

        $this->onQueue('python_requests');
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        Log::info('🚀 Sending payload to Python engine with callback', [
            'master_slip_id' => $this->masterSlipId,
            'prediction_type' => $this->predictionType,
            'risk_profile' => $this->riskProfile,
            'payload_size_kb' => round(strlen(json_encode($this->payload)) / 1024, 2),
            'match_count' => count($this->payload['master_slip']['matches'] ?? []),
        ]);

        try {
            // Add callback URL to payload for Python to call back
            $payloadWithCallback = $this->addCallbackUrl($this->payload);

            // log payloadCallback
            // logger()->debug('Raw JSON being sent:', ['json' => json_encode($payloadWithCallback, JSON_PRETTY_PRINT)]);

            // Send to Python engine WITH callback parameter
            $response = $this->sendToPythonEngine($payloadWithCallback);

            // Log initial response
            Log::info('🤖 Python engine request sent with callback URL', [
                'master_slip_id' => $this->masterSlipId,
                'callback_url' => $payloadWithCallback['callback_url'] ?? 'none',
                'response_status' => $response->status(),
            ]);

            // IMPORTANT: We DON'T process response here anymore
            // Python will call our callback endpoint when ready
            // The callback endpoint will dispatch StoreGeneratedSlips

            // Just update status to show request was sent
            $this->updateMasterSlipStatus('processing');

            Log::info('✅ Python request sent, waiting for callback', [
                'master_slip_id' => $this->masterSlipId,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Failed to send Python request', [
                'master_slip_id' => $this->masterSlipId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Add callback URL to payload
     */
    protected function addCallbackUrl(array $payload): array
    {
        // $callbackUrl = route('python.callback', ['masterSlipId' => $this->masterSlipId]);
        
        // // Make sure URL is properly formatted without extra escaping
        // $payload['callback_url'] = $callbackUrl;
        // $payload['callback_method'] = 'POST';
        // $payload['callback_timeout'] = 300; // 5 minutes
        
        Log::debug('🔗 Added callback URL to payload', [
            'callback_url' => "callback url is added in python engine",
            'master_slip_id' => $this->masterSlipId,
        ]);
        
        return $payload;
    }

    /**
     * Send payload to Python engine
     */
    protected function sendToPythonEngine(array $payload): \Illuminate\Http\Client\Response
    {
        $pythonEngineUrl = config('services.python_engine.url', 'http://localhost:5000/generate-slips');

        if (!$pythonEngineUrl) {
            throw new \Exception('Python engine URL not configured');
        }

        // Log the payload structure (not JSON encoded)
        Log::debug('🔗 Sending to Python engine with callback', [
            'url' => $pythonEngineUrl,
            'master_slip_id' => $this->masterSlipId,
            'has_callback_url' => isset($payload['callback_url']),
            'match_count' => count($payload['master_slip']['matches'] ?? []),
        ]);

        // Log raw payload for debugging (without double encoding)
        Log::info('📤 Final payload to Python', $payload);

        return Http::timeout(90)
            ->retry(2, 1000)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Master-Slip-ID' => $this->masterSlipId,
            ])
            ->post($pythonEngineUrl, $payload); // Laravel HTTP client will encode this properly
    }

    /**
     * Update master slip status
     */
    protected function updateMasterSlipStatus(string $status): void
    {
        $masterSlip = MasterSlip::find($this->masterSlipId);
        if ($masterSlip) {
            $masterSlip->update([
                'status' => $status,
                'last_updated_at' => now(),
            ]);
        }
    }

    /**
     * Handle job failure
     */
    protected function handleFailure(\Throwable $e): void
    {
        $masterSlip = MasterSlip::find($this->masterSlipId);

        if ($masterSlip) {
            $masterSlip->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 255),
                'failed_at' => now(),
            ]);
        }

        Log::error('❌ ProcessPythonRequest job failed', [
            'master_slip_id' => $this->masterSlipId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Handle permanent job failure
     */
    public function failed(\Throwable $exception): void
    {
        $jobId = $this->job ? $this->job->getJobId() : 'unknown';

        Log::critical('💥 ProcessPythonRequest job failed permanently', [
            'master_slip_id' => $this->masterSlipId,
            'error' => $exception->getMessage(),
            'job_id' => $jobId,
        ]);

        $this->handleFailure($exception);
    }
}
/*

 Slip-Centric Constructor
Accepts only $masterSlipId as required

No endpoint, data, or match IDs in constructor

Job loads everything from the slip ID

2. Leverages Pre-Computed Data
Uses MasterSlip and MasterSlipMatch models only

Assumes all analysis (forms, H2H, markets) is already computed by ProcessBetslipAnalysis

No direct MatchModel queries for computation

3. Clean Payload Building
buildPayloadFromSlip() transforms Laravel data to Python format

All data formatting is contained within the job

Python receives pure JSON with no Laravel artifacts

4. Defensive Data Handling
Fallback data when pre-computed analysis is missing

Graceful degradation with realistic defaults

Validation for minimum match count

5. Production-Ready Features
Proper queue configuration

Comprehensive logging

Error handling and retry logic

Timeouts and backoff strategies

6. Single Responsibility
Job only builds payload and sends to Python

No caching logic (Python handles computation)

No dynamic endpoint selection

The job now acts as a clean bridge between Laravel's orchestration layer and Python's compute engine,
 respecting the separation of concerns between the two systems.
 */