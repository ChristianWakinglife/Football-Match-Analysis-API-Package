<?php

namespace App\Jobs;

use App\Models\MasterSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAlternativeSlipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $retryAfter = 30;
    protected int $masterSlipId;

    /**
     * Create a new job instance
     */
    public function __construct(int $masterSlipId)
    {
        $this->masterSlipId = $masterSlipId;
    }

    /**
     * Execute the job
     */
    public function handle(): array
    {
        Log::info('🚀 Starting GenerateAlternativeSlipsJob', [
            'master_slip_id' => $this->masterSlipId
        ]);

        DB::beginTransaction();

        try {
            // 1. First job: Prepare ML data from master slip
            Log::info('📋 Preparing ML match data...');
            $prepareDataJob = new PrepareMLMatchDataJob($this->masterSlipId);
            $mlData = $prepareDataJob->handle(); // Get the prepared data
            
            Log::info('✅ ML data prepared', [
                'match_count' => count($mlData['master_slip']['matches'] ?? [])
            ]);

            // 2. Second job: Send to Python engine
            Log::info('🔗 Dispatching to Python engine...');
            ProcessPythonRequest::dispatch(
                $this->masterSlipId,
                $mlData // Pass the ML data to Python job
            );
            
            // Note: ProcessPythonRequest will automatically call StoreGeneratedSlips
            // when it receives the Python response via webhook/callback
            
            DB::commit();

            Log::info('✅ GenerateAlternativeSlipsJob completed', [
                'master_slip_id' => $this->masterSlipId,
                'next_step' => 'Python engine processing via ProcessPythonRequest job'
            ]);
            //update masterslip status to completed
            $masterslipStatus = MasterSlip::find($this->masterSlipId);
            if ($masterslipStatus) {
                $masterslipStatus->status = 'completed';
                $masterslipStatus->engine_status = 'completed';
                $masterslipStatus->save();
            }


            return [
                'success' => true,
                'message' => 'Jobs dispatched successfully',
                'master_slip_id' => $this->masterSlipId,
                'python_job_dispatched' => true,
                'ml_data_prepared' => true
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('❌ GenerateAlternativeSlipsJob failed', [
                'master_slip_id' => $this->masterSlipId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Handle permanent job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('💥 GenerateAlternativeSlipsJob failed permanently', [
            'master_slip_id' => $this->masterSlipId,
            'error' => $exception->getMessage()
        ]);
    }
}