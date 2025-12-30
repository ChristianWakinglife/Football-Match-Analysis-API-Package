<?php
// app/Jobs/ProcessPythonRequest.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MasterSlip;

class ProcessPythonRequestV2Engine implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [60, 120, 300];

    protected $endpoint;
    protected $payload;
    protected $method;
    protected $jobId;
    protected $masterSlipId;
    protected $predictionType;
    protected $riskProfile;

    public function __construct(
        $endpoint,
        $payload,
        $method = 'post',
        $jobId = null,
        $masterSlipId = null,
        $predictionType = 'ensemble',
        $riskProfile = 'medium'
    ) {
        $this->endpoint = $endpoint;
        $this->payload = $payload;
        $this->method = $method;
        $this->jobId = $jobId ?? uniqid('python_', true);
        $this->masterSlipId = $masterSlipId;
        $this->predictionType = $predictionType;
        $this->riskProfile = $riskProfile;

        $this->onQueue('python_requests');
    }

    public function handle()
    {
        Log::info('Processing Python request', [
            'job_id' => $this->jobId,
            'endpoint' => $this->endpoint,
            'master_slip_id' => $this->masterSlipId,
        ]);

        try {
            // Add authentication and headers
            $headers = [
                'Content-Type' => 'application/json',
                'X-API-Key' => config('services.python.api_key'),
                'X-Request-ID' => $this->jobId,
                'X-Prediction-Type' => $this->predictionType,
                'X-Risk-Profile' => $this->riskProfile,
            ];

            // Make the request with timeout and retry logic
            $response = Http::withHeaders($headers)
                        ->timeout(120)
                        ->retry(3, 1000)
                ->{$this->method}($this->endpoint, $this->payload);

            if ($response->successful()) {
                $results = $response->json();

                   // Validate response 

                   //TODO: make sure python sends array with generated_slips if not change this to what python sends 
                if (!isset($result['generated_slips']) || empty($result['generated_slips'])) {
                    throw new \Exception('Python engine returned no generated slips');
                }
        

                Log::info('Python request completed successfully', [
                    'job_id' => $this->jobId,
                    'master_slip_id' => $this->masterSlipId,
                    'processing_time' => $results['processing_time'] ?? null,
                ]);

                return $results;
            } else {
                throw new \Exception(
                    "Python API returned error: " . $response->status() .
                    " - " . $response->body()
                );
            }

        } catch (\Exception $e) {
            Log::error('Python request failed', [
                'job_id' => $this->jobId,
                'endpoint' => $this->endpoint,
                'master_slip_id' => $this->masterSlipId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update master slip status
            if ($this->masterSlipId) {
                $this->updateMasterSlipStatus('failed', $e->getMessage());
            }

            $this->fail($e);
        }
    }

    protected function storeResults($data)
    {
        try {
            $masterSlip = MasterSlip::find($this->masterSlipId);

            if (!$masterSlip) {
                Log::warning('Master slip not found for storing results', [
                    'master_slip_id' => $this->masterSlipId,
                ]);
                return;
            }

            // Store generated slips
            $generatedSlips = [];
            foreach ($data['generated_slips'] ?? [] as $generatedSlip) {
                $generatedSlips[] = [
                    'slip_id' => $generatedSlip['slip_id'],
                    'total_odds' => $generatedSlip['total_odds'],
                    'possible_return' => $generatedSlip['possible_return'],
                    'confidence_score' => $generatedSlip['confidence_score'],
                    'risk_level' => $generatedSlip['risk_level'],
                    'expected_value' => $generatedSlip['expected_value'],
                    'legs' => json_encode($generatedSlip['legs']),
                    'recommendations' => json_encode($generatedSlip['recommendations']),
                    'metadata' => json_encode($data['analysis_metadata']),
                ];
            }

            // Update master slip
            $masterSlip->update([
                'status' => 'completed',
                'processing_completed_at' => now(),
                'generated_slips' => json_encode($generatedSlips),
                'analysis_metadata' => json_encode($data['analysis_metadata']),
                'processing_time' => $data['processing_time'] ?? 0,
                'job_id' => $this->jobId,
            ]);

            Log::info('Stored Python analysis results', [
                'master_slip_id' => $this->masterSlipId,
                'generated_slips_count' => count($generatedSlips),
                'processing_time' => $data['processing_time'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to store Python results', [
                'master_slip_id' => $this->masterSlipId,
                'error' => $e->getMessage(),
            ]);

            // Update status to indicate storage failure
            $this->updateMasterSlipStatus('storage_failed', $e->getMessage());
        }
    }

    protected function updateMasterSlipStatus($status, $errorMessage = null)
    {
        try {
            $masterSlip = MasterSlip::find($this->masterSlipId);

            if ($masterSlip) {
                $updateData = [
                    'status' => $status,
                    'failed_at' => now(),
                ];

                if ($errorMessage) {
                    $updateData['error_message'] = substr($errorMessage, 0, 500);
                }

                $masterSlip->update($updateData);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update master slip status', [
                'master_slip_id' => $this->masterSlipId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::critical('ProcessPythonRequest job failed', [
            'job_id' => $this->jobId,
            'master_slip_id' => $this->masterSlipId,
            'endpoint' => $this->endpoint,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->updateMasterSlipStatus('job_failed', $exception->getMessage());
    }
}