<?php
/**
 * 
 * 1. Full Integration with GeneratorJob Model:
Uses all fields from your migration

Proper relationships with masterSlip and user

Comprehensive job lifecycle management

2. Intuitive Job Workflow:
Create Job → Store in database with initial status

Dispatch → Send to Python service via ProcessPythonRequest job

Webhook → Python service calls back with results

Auto-create Slips → Generated slips automatically created from results

3. Advanced Features:
Job timeout detection (auto-fails jobs >30 minutes)

Queue cancellation attempts (if using Horizon/Redis)

Automatic slip creation from successful jobs

Rich filtering & sorting in listJobs


 * 
 * 
 * 
 * 
 */
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSlipsJob;
use App\Jobs\ProcessPythonRequest;
use App\Models\GeneratorJob;
use App\Models\Slip;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;
use Laravel\Horizon\Horizon;

class GeneratorController extends Controller
{
    // Job status constants - matching your migration
    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';
    private const STATUS_CANCELLED = 'cancelled';

    // Strategy constants
    private const STRATEGIES = [
        'monte_carlo',
        'coverage',
        'ml_prediction',
        'mixed',
        'value_betting',
        'arbitrage'
    ];

    // Risk profiles
    private const RISK_PROFILES = [
        'conservative',
        'balanced',
        'aggressive',
        'lottery'
    ];

    /**
     * Generate slips from a master slip.
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Validate the request
            $validator = Validator::make($request->all(), [
                'master_slip_id' => 'required|exists:slips,id',
                'strategy' => 'required|string|in:' . implode(',', self::STRATEGIES),
                'options' => 'nullable|array',
                'options.max_slips' => 'nullable|integer|min:1|max:500',
                'options.risk_profile' => 'nullable|in:' . implode(',', self::RISK_PROFILES),
                'options.min_odds' => 'nullable|numeric|min:1.01',
                'options.max_odds' => 'nullable|numeric|gte:options.min_odds',
                'options.min_confidence' => 'nullable|numeric|min:0|max:1',
                'options.diversification' => 'nullable|numeric|min:0|max:1',
                'options.max_matches_per_slip' => 'nullable|integer|min:2|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            // Get the master slip with relationships
            $masterSlip = Slip::with(['matches.matchPredictions', 'matches.odds'])
                ->findOrFail($validated['master_slip_id']);

            // Check if slip has enough matches
            if ($masterSlip->matches->count() < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Master slip must have at least 2 matches to generate slips',
                ], 422);
            }

            // Create generator job record
            $job = GeneratorJob::create([
                'job_id' => \Illuminate\Support\Str::uuid()->toString(),
                'master_slip_id' => $masterSlip->id,
                'master_slip_name' => $masterSlip->name,
                'strategy' => $validated['strategy'],
                'options' => $validated['options'] ?? [],
                'status' => self::STATUS_PENDING,
                'progress' => 0,
                'total_slips' => 0,
                'generated_slips' => 0,
                'queued_at' => now(),
                'created_by' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            // Prepare data for Python service
            $pythonRequestData = $this->preparePythonRequestData($masterSlip, $validated);

            Log::info('Generator job created', [
                'job_id' => $job->job_id,
                'generator_job_id' => $job->id,
                'master_slip_id' => $masterSlip->id,
                'strategy' => $validated['strategy'],
                'user_id' => $request->user()?->id,
            ]);

            // Option 1: Use ProcessPythonRequest job (calls Python service)
            $this->dispatchPythonJob($job, $pythonRequestData);

            // Option 2: Use GenerateSlipsJob (simulated processing)
            // GenerateSlipsJob::dispatch($pythonRequestData, $validated['options'], $request->user()?->id)
            //     ->onQueue('generator');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Generator job created and queued successfully',
                'data' => $this->formatJobResponse($job),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create generator job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create generator job',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get the status of a generator job.
     */
    public function status(string $jobId): JsonResponse
    {
        try {
            // Try to find by job_id first, then by id
            $job = GeneratorJob::where('job_id', $jobId)
                ->orWhere('id', $jobId)
                ->with(['masterSlip', 'user'])
                ->first();

            if (!$job) {
                return response()->json([
                    'success' => false,
                    'message' => 'Generator job not found',
                ], 404);
            }

            // If job is processing and started more than 30 minutes ago, mark as stale
            if (
                $job->status === self::STATUS_PROCESSING &&
                $job->started_at &&
                $job->started_at->diffInMinutes(now()) > 30
            ) {

                $job->update([
                    'status' => self::STATUS_FAILED,
                    'error_message' => 'Job processing timeout (30+ minutes)',
                    'completed_at' => now(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatJobResponse($job, true),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve job status', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve job status',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * List all generator jobs with filters.
     */
    public function listJobs(Request $request): JsonResponse
    {
        try {
            // Build query
            $query = GeneratorJob::query()->with(['masterSlip', 'user']);

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->get('status'));
            }

            if ($request->has('strategy')) {
                $query->where('strategy', $request->get('strategy'));
            }

            if ($request->has('user_id')) {
                $query->where('created_by', $request->get('user_id'));
            }

            if ($request->has('master_slip_id')) {
                $query->where('master_slip_id', $request->get('master_slip_id'));
            }

            // Date filters
            if ($request->has('date_from')) {
                $query->where('created_at', '>=', Carbon::parse($request->get('date_from')));
            }

            if ($request->has('date_to')) {
                $query->where('created_at', '<=', Carbon::parse($request->get('date_to'))->endOfDay());
            }

            // Search by job ID or slip name
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('job_id', 'like', "%{$search}%")
                        ->orWhere('master_slip_name', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginate results
            $perPage = $request->get('per_page', 20);
            $jobs = $query->paginate($perPage);

            // Get statistics
            $stats = [
                'total' => GeneratorJob::count(),
                'by_status' => GeneratorJob::groupBy('status')
                    ->selectRaw('status, count(*) as count')
                    ->pluck('count', 'status')
                    ->toArray(),
                'by_strategy' => GeneratorJob::groupBy('strategy')
                    ->selectRaw('strategy, count(*) as count')
                    ->pluck('count', 'strategy')
                    ->toArray(),
            ];

            return response()->json([
                'success' => true,
                'data' => $jobs->items(),
                'meta' => [
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'per_page' => $jobs->perPage(),
                    'total' => $jobs->total(),
                    'statistics' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list generator jobs', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to list generator jobs',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Cancel a generator job.
     */
    public function cancel(string $jobId): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Find the job
            $job = GeneratorJob::where('job_id', $jobId)
                ->orWhere('id', $jobId)
                ->first();

            if (!$job) {
                return response()->json([
                    'success' => false,
                    'message' => 'Generator job not found',
                ], 404);
            }

            // Check if job can be cancelled
            if (!in_array($job->status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
                return response()->json([
                    'success' => false,
                    'message' => sprintf(
                        'Job cannot be cancelled in its current status: %s',
                        $job->status
                    ),
                ], 422);
            }

            // Update job status
            $job->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => request()->user()?->id,
                'error_message' => 'Cancelled by user',
            ]);

            // Attempt to remove from queue (if using Laravel Horizon/Redis)
            $this->attemptQueueCancellation($job);

            DB::commit();

            Log::info('Generator job cancelled', [
                'job_id' => $job->job_id,
                'generator_job_id' => $job->id,
                'cancelled_by' => request()->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Generator job cancelled successfully',
                'data' => $this->formatJobResponse($job),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel generator job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel generator job',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Webhook endpoint for Python service to update job status.
     * This is called by the Python backend when job completes/fails.
     */
    public function webhook(Request $request, string $jobId): JsonResponse
    {
        try {
            // Validate webhook request with signature verification
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:processing,completed,failed',
                'progress' => 'nullable|integer|min:0|max:100',
                'total_slips' => 'nullable|integer|min:0',
                'generated_slips' => 'nullable|integer|min:0',
                'result' => 'nullable|array',
                'error_message' => 'nullable|string',
                'generated_slips_data' => 'nullable|array',
                'statistics' => 'nullable|array',
                'signature' => 'required|string', // For webhook security
            ]);

            if ($validator->fails()) {
                Log::warning('Invalid webhook request', [
                    'job_id' => $jobId,
                    'errors' => $validator->errors()->toArray(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid request',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // Verify signature (implement based on your security requirements)
            if (!$this->verifyWebhookSignature($request)) {
                Log::warning('Webhook signature verification failed', [
                    'job_id' => $jobId,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                ], 401);
            }

            // Find the job
            $job = GeneratorJob::where('job_id', $jobId)->first();

            if (!$job) {
                Log::warning('Webhook received for non-existent job', ['job_id' => $jobId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Job not found',
                ], 404);
            }

            // Update job based on webhook data
            $updateData = [
                'status' => $data['status'],
            ];

            if ($data['status'] === 'processing' && !$job->started_at) {
                $updateData['started_at'] = now();
            }

            if (in_array($data['status'], ['completed', 'failed', 'cancelled'])) {
                $updateData['completed_at'] = now();
            }

            if (isset($data['progress'])) {
                $updateData['progress'] = $data['progress'];
            }

            if (isset($data['total_slips'])) {
                $updateData['total_slips'] = $data['total_slips'];
            }

            if (isset($data['generated_slips'])) {
                $updateData['generated_slips'] = $data['generated_slips'];
            }

            if (isset($data['error_message'])) {
                $updateData['error_message'] = $data['error_message'];
            }

            // Store result data if provided
            if (isset($data['result']) || isset($data['generated_slips_data'])) {
                $resultData = [
                    'result' => $data['result'] ?? null,
                    'generated_slips_data' => $data['generated_slips_data'] ?? null,
                    'statistics' => $data['statistics'] ?? null,
                    'received_at' => now()->toISOString(),
                ];

                $updateData['result_data'] = $resultData;
            }

            $job->update($updateData);

            Log::info('Generator job updated via webhook', [
                'job_id' => $job->job_id,
                'generator_job_id' => $job->id,
                'status' => $data['status'],
                'progress' => $data['progress'] ?? null,
            ]);

            // If job completed successfully, create the generated slips
            if ($data['status'] === 'completed' && isset($data['generated_slips_data'])) {
                $this->createGeneratedSlips($job, $data['generated_slips_data']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Job status updated successfully',
                'data' => [
                    'job_id' => $job->job_id,
                    'status' => $job->status,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process webhook', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Prepare data for Python service request.
     */
    private function preparePythonRequestData(Slip $masterSlip, array $validated): array
    {
        return [
            'master_slip' => [
                'id' => $masterSlip->id,
                'name' => $masterSlip->name,
                'matches' => $masterSlip->matches->map(function ($match) {
                    $prediction = $match->matchPredictions->last();
                    $odds = $match->odds ?? $match->matchOdds->last();

                    return [
                        'id' => $match->id,
                        'home_team' => $match->home_team,
                        'away_team' => $match->away_team,
                        'league' => $match->league,
                        'match_date' => $match->match_date,
                        'prediction' => $prediction ? [
                            'outcome' => $prediction->predicted_outcome,
                            'confidence' => $prediction->confidence,
                            'probabilities' => [
                                'home' => $prediction->home_probability,
                                'draw' => $prediction->draw_probability,
                                'away' => $prediction->away_probability,
                            ],
                        ] : null,
                        'odds' => $odds ? [
                            'home' => $odds->home_odds,
                            'draw' => $odds->draw_odds,
                            'away' => $odds->away_odds,
                        ] : null,
                    ];
                })->toArray(),
            ],
            'options' => $validated['options'] ?? [],
            'request_id' => \Illuminate\Support\Str::uuid()->toString(),
            'callback_url' => route('api.generator.webhook', ['jobId' => ':jobId']),
        ];
    }

    /**
     * Dispatch job to Python service.
     */
    private function dispatchPythonJob(GeneratorJob $job, array $pythonRequestData): void
    {
        // Replace placeholder with actual job ID in callback URL
        $pythonRequestData['callback_url'] = str_replace(
            ':jobId',
            $job->job_id,
            $pythonRequestData['callback_url']
        );

        // Dispatch ProcessPythonRequest job
        ProcessPythonRequest::dispatch(
            $pythonRequestData,
            '/api/v1/generate-slips', // Python endpoint
            $job->job_id
        )->onQueue('generator');

        // Update job with queue information
        $job->update([
            'queued_at' => now(),
        ]);
    }

    /**
     * Attempt to cancel job from queue.
     */
    private function attemptQueueCancellation(GeneratorJob $job): void
    {
        try {
            // If using Laravel Horizon
            if (class_exists('Laravel\Horizon\Horizon')) {
                \Laravel\Horizon\Horizon::forget($job->job_id);
            }

            // If using Redis queue
            if (config('queue.default') === 'redis') {
                // This is a simplified approach - in production you'd need 
                // a more robust queue cancellation mechanism
                Log::info('Queue cancellation attempted for job', [
                    'job_id' => $job->job_id,
                    'queue_driver' => config('queue.default'),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cancel job from queue', [
                'job_id' => $job->job_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create generated slips from job results.
     */
    private function createGeneratedSlips(GeneratorJob $job, array $slipsData): void
    {
        try {
            DB::beginTransaction();

            $createdCount = 0;

            foreach ($slipsData as $slipData) {
                // Create new slip based on generated data
                $slip = Slip::create([
                    'name' => sprintf(
                        'Generated from %s - %s',
                        $job->master_slip_name,
                        $slipData['strategy'] ?? 'mixed'
                    ),
                    'type' => 'generated',
                    'source_job_id' => $job->id,
                    'total_odds' => $slipData['total_odds'] ?? 1.0,
                    'probability' => $slipData['probability'] ?? 0.0,
                    'expected_value' => $slipData['expected_value'] ?? 0.0,
                    'confidence' => $slipData['confidence'] ?? 0.0,
                    'strategy' => $slipData['strategy'] ?? $job->strategy,
                    'risk_profile' => $slipData['risk_profile'] ?? ($job->options['risk_profile'] ?? 'balanced'),
                    'created_by' => $job->created_by,
                ]);

                // Attach matches if provided
                if (isset($slipData['matches']) && is_array($slipData['matches'])) {
                    $matchIds = collect($slipData['matches'])->pluck('id')->filter()->toArray();
                    if (!empty($matchIds)) {
                        $slip->matches()->sync($matchIds);
                    }
                }

                $createdCount++;
            }

            DB::commit();

            Log::info('Generated slips created from job', [
                'job_id' => $job->job_id,
                'generator_job_id' => $job->id,
                'slips_created' => $createdCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create generated slips', [
                'job_id' => $job->job_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify webhook signature (implement based on your security needs).
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        // Implement your signature verification logic
        // For example, using HMAC:
        $expectedSignature = hash_hmac(
            'sha256',
            $request->getContent(),
            config('services.python.webhook_secret')
        );

        return hash_equals($expectedSignature, $request->get('signature', ''));

        // For development, you might want to disable verification
        // return config('app.env') === 'local' || config('app.env') === 'testing';
    }

    /**
     * Format job response consistently.
     */
    private function formatJobResponse(GeneratorJob $job, bool $includeDetails = false): array
    {
        $response = [
            'job_id' => $job->job_id,
            'generator_job_id' => $job->id,
            'status' => $job->status,
            'strategy' => $job->strategy,
            'progress' => $job->progress,
            'total_slips' => $job->total_slips,
            'generated_slips' => $job->generated_slips,
            'master_slip' => $job->masterSlip ? [
                'id' => $job->masterSlip->id,
                'name' => $job->masterSlip->name,
            ] : null,
            'created_at' => $job->created_at->toISOString(),
            'started_at' => $job->started_at?->toISOString(),
            'completed_at' => $job->completed_at?->toISOString(),
            'estimated_remaining_time' => $this->estimateRemainingTime($job),
        ];

        if ($includeDetails) {
            $response['details'] = [
                'options' => $job->options,
                'error_message' => $job->error_message,
                'result_data' => $job->result_data,
                'user' => $job->user ? [
                    'id' => $job->user->id,
                    'name' => $job->user->name,
                ] : null,
                'duration' => $job->started_at ?
                    $job->completed_at ?
                    $job->started_at->diffInSeconds($job->completed_at) . ' seconds' :
                    $job->started_at->diffInSeconds(now()) . ' seconds (so far)' :
                    null,
            ];
        }

        return $response;
    }

    /**
     * Estimate remaining time for processing job.
     */
    private function estimateRemainingTime(GeneratorJob $job): ?string
    {
        if ($job->status !== self::STATUS_PROCESSING || !$job->started_at || $job->progress <= 0) {
            return null;
        }

        $elapsedSeconds = $job->started_at->diffInSeconds(now());
        $estimatedTotalSeconds = ($elapsedSeconds / $job->progress) * 100;
        $remainingSeconds = max(0, $estimatedTotalSeconds - $elapsedSeconds);

        return $remainingSeconds > 60 ?
            ceil($remainingSeconds / 60) . ' minutes' :
            ceil($remainingSeconds) . ' seconds';
    }


    public function destroyAllByMasterSlip($masterSlipId)
{
    Log::info('🗑️ Deleting all generated slips for master slip', [
        'master_slip_id' => $masterSlipId,
    ]);

    try {
        // Find the master slip first
        $masterSlip = MasterSlip::find($masterSlipId);
        
        if (!$masterSlip) {
            Log::warning('❌ Master slip not found for deletion', [
                'master_slip_id' => $masterSlipId,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Master slip not found',
            ], 404);
        }

        // Get count before deletion for logging
        $generatedSlipsCount = $masterSlip->generatedSlips()->count();
        
        // Delete all generated slips related to this master slip
        $deletedCount = $masterSlip->generatedSlips()->delete();
        
        // Update master slip status to show no generated slips
        $masterSlip->update([
            'total_generated_slips' => 0,
            'failed_slips_count' => 0,
            'engine_version' => null,
            'status' => 'ready',
        ]);

        Log::info('✅ All generated slips deleted', [
            'master_slip_id' => $masterSlipId,
            'deleted_count' => $deletedCount,
            'previous_count' => $generatedSlipsCount,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Successfully deleted all {$deletedCount} generated slips",
            'deleted_count' => $deletedCount,
            'master_slip_id' => $masterSlipId,
        ]);

    } catch (\Exception $e) {
        Log::error('💥 Failed to delete generated slips', [
            'master_slip_id' => $masterSlipId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete generated slips',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
}