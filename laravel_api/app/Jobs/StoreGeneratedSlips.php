<?php

namespace App\Jobs;

use App\Models\MasterSlip;
use App\Models\GeneratedSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StoreGeneratedSlips implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The master slip database ID (not the string ID from Python)
     */
    protected int $masterSlipId;

    /**
     * The full Python response containing generated_slips and metadata
     */
    protected array $pythonResponse;

    /**
     * Create a new job instance.
     */
    public function __construct(int $masterSlipId, array $pythonResponse)
    {
        $this->masterSlipId = $masterSlipId;
        $this->pythonResponse = $pythonResponse;

        // Use the same queue as Python communication for consistency
        $this->onQueue('python_engine');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('📦 Starting to store generated slips', [
            'master_slip_id' => $this->masterSlipId,
            'python_master_slip_id' => $this->pythonResponse['master_slip_id'] ?? 'not_provided',
            'generated_slips_count' => count($this->pythonResponse['generated_slips'] ?? []),
            'total_slips_from_metadata' => $this->pythonResponse['metadata']['total_slips'] ?? 0,
            'engine_version' => $this->pythonResponse['metadata']['engine_version'] ?? 'unknown',
        ]);

        // FIX: Use find() instead of findOrFail() to handle ID 0
        $masterSlip = MasterSlip::find($this->masterSlipId);

        if (!$masterSlip) {
            Log::critical('💥 Master slip not found, cannot store generated slips', [
                'master_slip_id' => $this->masterSlipId,
                'python_master_slip_id' => $this->pythonResponse['master_slip_id'] ?? 'unknown',
                'available_master_slip_ids' => MasterSlip::latest()->take(5)->pluck('id')->toArray(),
            ]);
            return; // Exit early, don't throw exception
        }

        // Optional: Clear old generated slips if you want to replace them
        // $masterSlip->generatedSlips()->delete();

        $storedCount = 0;
        $failedCount = 0;

        foreach ($this->pythonResponse['generated_slips'] ?? [] as $slipData) {
            try {
                // Create the main generated slip record with all new fields
                $generatedSlip = $masterSlip->generatedSlips()->create([
                    'slip_id' => $slipData['slip_id'],
                    'stake' => $slipData['stake'],
                    'total_odds' => $slipData['total_odds'],
                    'possible_return' => $slipData['possible_return'],
                    'risk_level' => $slipData['risk_level'],
                    'confidence_score' => $slipData['confidence_score'],
                    'variation_type' => $slipData['variation_type'] ?? null, // New field
                    'edge_score' => $slipData['edge_score'] ?? 0.0, // New field
                    'error' => $slipData['error'] ?? null, // New field (nullable)
                    'raw_data' => $slipData, // fallback full data
                ]);

                // Store each leg with the is_fallback field
                foreach ($slipData['legs'] as $leg) {
                    $generatedSlip->legs()->create([
                        'match_id' => $leg['match_id'],
                        'market' => $leg['market'],
                        'selection' => $leg['selection'],
                        'odds' => $leg['odds'],
                        'is_fallback' => $leg['is_fallback'] ?? false, // New field
                    ]);
                }

                $storedCount++;

            } catch (\Exception $e) {
                Log::error('❌ Failed to store slip', [
                    'master_slip_id' => $this->masterSlipId,
                    'slip_id' => $slipData['slip_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'slip_data_sample' => array_keys($slipData),
                ]);
                $failedCount++;
            }
        }

        // Update master slip status with metadata
        $updateData = [
            'status' => 'processed',
            'processed_at' => now(),
            'total_generated_slips' => $storedCount,
            'failed_slips_count' => $failedCount,
            'engine_version' => $this->pythonResponse['metadata']['engine_version'] ?? null,
            'processing_time_ms' => $this->pythonResponse['metadata']['processing_time_ms'] ?? null,
        ];

        // If all slips failed, mark as partially_failed
        if ($failedCount > 0 && $storedCount === 0) {
            $updateData['status'] = 'partially_failed';
            $updateData['error_message'] = "Failed to store all {$failedCount} generated slips";
        } elseif ($failedCount > 0) {
            $updateData['status'] = 'partially_processed';
            $updateData['error_message'] = "Successfully stored {$storedCount} slips, failed to store {$failedCount} slips";
        }

        $masterSlip->update($updateData);

        Log::info('✅ Generated slips storage completed', [
            'master_slip_id' => $this->masterSlipId,
            'stored_count' => $storedCount,
            'failed_count' => $failedCount,
            'status' => $updateData['status'],
            'master_slip_custom_id' => $masterSlip->custom_id ?? 'none',
        ]);
    }
}