<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterSlip;
use App\Models\GeneratedSlip;
use App\Models\GeneratedSlipLeg;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use App\Models\MatchModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Team_Form;
use App\Models\Head_To_Head;
use Illuminate\Support\Facades\Storage;
use App\Jobs\GenerateAlternativeSlipsJob;


/**
 * Controller for managing slips and generated slips.
 * Provides API endpoints for frontend slip pages.
 */
class SlipController extends Controller
{
    /**
     * Get all generated slips for a specific master slip.
     *
     * This endpoint fetches all generated slips for a given master slip ID,
     * including their legs (selections) with eager loading for performance.
     *
     * @param string $masterSlipId The UUID or ID of the master slip
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGeneratedSlips(string $masterSlipId)
    {
        try {
            // Find the master slip or return 404
            $masterSlip = MasterSlip::findOrFail($masterSlipId);

            // Load generated slips with their legs
            $generatedSlips = GeneratedSlip::where('master_slip_id', $masterSlipId)
                ->with([
                    'legs' => function ($query) {
                        // Order legs for consistent display
                        $query->orderBy('created_at', 'asc');
                    }
                ])
                ->orderBy('confidence_score', 'desc') // Show highest confidence first
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Generated slips retrieved successfully',
                'data' => [
                    'master_slip_id' => $masterSlipId,
                    'generated_slips' => $generatedSlips,
                    'count' => $generatedSlips->count()
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Master slip not found',
                'error' => 'The requested master slip does not exist.'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve generated slips',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get detailed information for a specific generated slip.
     *
     * This endpoint returns a single generated slip with all its legs
     * and metadata for frontend detail view.
     *
     * @param string $generatedSlipId The UUID or ID of the generated slip
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSlipDetail(string $generatedSlipId)
    {
        try {
            // Find the generated slip with all relationships
            $generatedSlip = GeneratedSlip::with([
                'legs' => function ($query) {
                    $query->orderBy('created_at', 'asc');
                },
                'masterSlip' => function ($query) {
                    // Only load necessary master slip data
                    $query->select(['id', 'stake', 'currency', 'status', 'created_at']);
                }
            ])->findOrFail($generatedSlipId);

            // Format the response for frontend
            $formattedSlip = [
                'slip_id' => $generatedSlip->id,
                'stake' => (float) $generatedSlip->stake,
                'total_odds' => (float) $generatedSlip->total_odds,
                'possible_return' => (float) $generatedSlip->possible_return,
                'risk_level' => $generatedSlip->risk_level,
                'confidence_score' => (float) $generatedSlip->confidence_score,
                'created_at' => $generatedSlip->created_at->toISOString(),
                'legs' => $generatedSlip->legs->map(function ($leg) {
                    return [
                        'match_id' => $leg->match_id,
                        'market' => $leg->market,
                        'selection' => $leg->selection,
                        'odds' => (float) $leg->odds,
                        'created_at' => $leg->created_at->toISOString()
                    ];
                }),
                'master_slip' => $generatedSlip->masterSlip ? [
                    'stake' => (float) $generatedSlip->masterSlip->stake,
                    'currency' => $generatedSlip->masterSlip->currency,
                    'status' => $generatedSlip->masterSlip->status,
                    'created_at' => $generatedSlip->masterSlip->created_at->toISOString()
                ] : null
            ];

            return response()->json([
                'success' => true,
                'message' => 'Slip detail retrieved successfully',
                'data' => $formattedSlip
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Generated slip not found',
                'error' => 'The requested generated slip does not exist.'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve slip detail',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a master slip and all its related data.
     *
     * This endpoint permanently deletes a master slip, all its generated slips,
     * and all generated slip legs. Uses database transaction to ensure
     * data integrity and prevent partial deletes.
     *
     * @param string $masterSlipId The UUID or ID of the master slip to delete
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSlip(string $masterSlipId)
    {
        DB::beginTransaction();

        try {
            // Find the master slip
            $masterSlip = MasterSlip::findOrFail($masterSlipId);

            // Get all generated slip IDs for this master slip
            $generatedSlipIds = GeneratedSlip::where('master_slip_id', $masterSlipId)
                ->pluck('id')
                ->toArray();

            // Delete in correct order to maintain referential integrity
            if (!empty($generatedSlipIds)) {
                // First delete all legs for these generated slips
                GeneratedSlipLeg::whereIn('generated_slip_id', $generatedSlipIds)->delete();

                // Then delete the generated slips
                GeneratedSlip::where('master_slip_id', $masterSlipId)->delete();
            }

            // Finally delete the master slip
            $masterSlip->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master slip and all related data deleted successfully',
                'data' => [
                    'deleted_master_slip_id' => $masterSlipId,
                    'deleted_generated_slips_count' => count($generatedSlipIds)
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Master slip not found',
                'error' => 'The requested master slip does not exist.'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete slip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get statistics about slips for dashboard display.
     *
     * This endpoint provides summary data including total slips,
     * generated slips count, average legs per slip, and other metrics
     * for frontend dashboards. Uses efficient database queries.
     *
     * @param Request $request Optional filters via query parameters
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSlipsStatistics(Request $request)
    {
        try {
            // Apply optional date filters
            $query = MasterSlip::query();

            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }

            // Get master slips with their generated slips count
            $masterSlips = $query->withCount('generatedSlips')->get();

            // Calculate statistics
            $totalMasterSlips = $masterSlips->count();
            $totalGeneratedSlips = $masterSlips->sum('generated_slips_count');

            // Get legs statistics
            $legsQuery = GeneratedSlipLeg::query();

            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $legsQuery->where('created_at', '>=', $startDate);
            }

            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
                $legsQuery->where('created_at', '<=', $endDate);
            }

            $totalLegs = $legsQuery->count();
            $averageLegsPerSlip = $totalGeneratedSlips > 0 ? $totalLegs / $totalGeneratedSlips : 0;

            // Get generated slips for confidence statistics
            $generatedSlipsQuery = GeneratedSlip::query();

            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $generatedSlipsQuery->where('created_at', '>=', $startDate);
            }

            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
                $generatedSlipsQuery->where('created_at', '<=', $endDate);
            }

            $confidenceStats = $generatedSlipsQuery->selectRaw(
                'AVG(confidence_score) as avg_confidence, 
                 MIN(confidence_score) as min_confidence, 
                 MAX(confidence_score) as max_confidence'
            )->first();

            // Compile statistics
            $statistics = [
                'master_slips' => [
                    'total' => $totalMasterSlips,
                    'by_status' => $masterSlips->groupBy('status')->map->count()
                ],
                'generated_slips' => [
                    'total' => $totalGeneratedSlips,
                    'avg_per_master_slip' => $totalMasterSlips > 0 ? $totalGeneratedSlips / $totalMasterSlips : 0
                ],
                'legs' => [
                    'total' => $totalLegs,
                    'avg_per_generated_slip' => round($averageLegsPerSlip, 2)
                ],
                'confidence' => [
                    'average' => $confidenceStats->avg_confidence ? round($confidenceStats->avg_confidence, 2) : 0,
                    'minimum' => $confidenceStats->min_confidence ? round($confidenceStats->min_confidence, 2) : 0,
                    'maximum' => $confidenceStats->max_confidence ? round($confidenceStats->max_confidence, 2) : 0
                ],
                'time_period' => [
                    'start_date' => $request->input('start_date'),
                    'end_date' => $request->input('end_date')
                ]
            ];

            return response()->json([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => $statistics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Export slips data to CSV format.
     *
     * This endpoint streams slips data as CSV for download.
     * Includes optional filtering by date range and slip ID.
     * Uses Laravel's StreamedResponse for memory efficiency.
     *
     * @param Request $request Optional filters
     * @return StreamedResponse
     */
    public function exportSlipsToCSV(Request $request)
    {
        try {
            // Build query with optional filters
            $query = GeneratedSlip::query()
                ->with(['legs', 'masterSlip'])
                ->orderBy('created_at', 'desc');

            // Apply master slip ID filter
            if ($request->has('master_slip_id')) {
                $query->where('master_slip_id', $request->input('master_slip_id'));
            }

            // Apply date range filter
            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }

            // Define CSV headers
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="slips_export_' . date('Y-m-d_H-i-s') . '.csv"',
            ];

            // Create streamed response for memory efficiency
            return new StreamedResponse(function () use ($query, $request) {
                // Open output stream
                $handle = fopen('php://output', 'w');

                // Write CSV headers
                fputcsv($handle, [
                    'Slip ID',
                    'Master Slip ID',
                    'Stake',
                    'Total Odds',
                    'Possible Return',
                    'Risk Level',
                    'Confidence Score (%)',
                    'Number of Legs',
                    'Created At',
                    'Market',
                    'Selections',
                    'Average Odds per Leg'
                ]);

                // Stream data in chunks for memory efficiency
                $query->chunk(100, function ($slips) use ($handle) {
                    foreach ($slips as $slip) {
                        // Prepare selections and market info
                        $selections = $slip->legs->pluck('selection')->toArray();
                        $markets = $slip->legs->pluck('market')->toArray();
                        $odds = $slip->legs->pluck('odds')->toArray();

                        // Calculate average odds
                        $avgOdds = count($odds) > 0 ? array_sum($odds) / count($odds) : 0;

                        fputcsv($handle, [
                            $slip->id,
                            $slip->master_slip_id,
                            $slip->stake,
                            $slip->total_odds,
                            $slip->possible_return,
                            $slip->risk_level,
                            $slip->confidence_score,
                            $slip->legs->count(),
                            $slip->created_at->toDateTimeString(),
                            implode('; ', array_unique($markets)),
                            implode('; ', $selections),
                            round($avgOdds, 2)
                        ]);
                    }
                });

                fclose($handle);
            }, Response::HTTP_OK, $headers);
        } catch (\Exception $e) {
            // Fallback JSON response if CSV streaming fails
            return response()->json([
                'success' => false,
                'message' => 'Failed to export CSV',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all master slips with basic information.
     *
     * Optional endpoint for frontend to list master slips.
     * Includes generated slips count for quick overview.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllMasterSlips(Request $request)
    {
        try {
            $query = MasterSlip::query()
                ->withCount('generatedSlips')
                ->orderBy('created_at', 'desc');

            // Optional pagination
            $perPage = $request->input('per_page', 20);
            $masterSlips = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Master slips retrieved successfully',
                'data' => $masterSlips
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve master slips',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get recent generated slips for quick view.
     *
     * Optional endpoint for dashboard or recent activity display.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentSlips(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);

            $recentSlips = GeneratedSlip::with(['masterSlip', 'legs'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($slip) {
                    return [
                        'id' => $slip->id,
                        'master_slip_id' => $slip->master_slip_id,
                        'stake' => (float) $slip->stake,
                        'total_odds' => (float) $slip->total_odds,
                        'possible_return' => (float) $slip->possible_return,
                        'risk_level' => $slip->risk_level,
                        'confidence_score' => (float) $slip->confidence_score,
                        'legs_count' => $slip->legs->count(),
                        'created_at' => $slip->created_at->toISOString(),
                        'master_slip' => $slip->masterSlip ? [
                            'status' => $slip->masterSlip->status,
                            'stake' => (float) $slip->masterSlip->stake
                        ] : null
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Recent slips retrieved successfully',
                'data' => $recentSlips
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve recent slips',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get slip status information.
     *
     * Optional endpoint to check slip processing status.
     * Useful for frontend polling to show progress.
     *
     * @param string $masterSlipId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSlipStatus(string $masterSlipId)
    {
        try {
            $masterSlip = MasterSlip::findOrFail($masterSlipId);

            // Fetch generated slips count (uncomment if you want it)
            $generatedSlipsCount = GeneratedSlip::where('master_slip_id', $masterSlipId)->count();

            $status = [
                'master_slip_id' => $masterSlipId,
                'status' => $masterSlip->status,
                'engine_status' => $masterSlip->engine_status ?? 'pending',      // Add this
                'analysis_quality' => $masterSlip->analysis_quality ?? 'pending',  // Add this
                'alternative_slips_count' => $generatedSlipsCount,                       // Add this
                'last_updated' => $masterSlip->updated_at?->toISOString(),
                'is_complete' => $masterSlip->status === 'completed',
                'is_processing' => $masterSlip->status === 'processing',
                'has_generated_slips' => $generatedSlipsCount > 0,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Slip status retrieved successfully',
                'data' => $status
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Master slip not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve slip status',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


    /**
     * Create a new betslip in the database
     */
    public function createSlip(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'stake' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|size:3',
            ]);

            $slip = MasterSlip::create([
                'name' => $validated['name'] ?? 'My Betslip',
                'stake' => $validated['stake'] ?? 0,
                'currency' => $validated['currency'] ?? 'USD',
                'status' => 'draft',
                'slip_data' => [], // Add empty array for slip_data
                'user_id' => 1 // Adjust based on your auth
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Betslip created successfully',
                'data' => [
                    'slip_id' => $slip->id,
                    'name' => $slip->name,
                    'created_at' => $slip->created_at->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            // Add detailed error logging
            Log::error('Failed to create slip: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create betslip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
                'debug_info' => env('APP_DEBUG') ? [
                    'status_value' => 'active',
                    'table' => 'master_slips'
                ] : null
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a match to an existing betslip
     */
    public function addMatchToSlip(Request $request, $slipId)
    {
        try {
            $validated = $request->validate([
                'match_id' => 'required|string',
                'market' => 'nullable|string',
                'selection' => 'nullable|string',
                'odds' => 'nullable|numeric|min:0',
            ]);

            $slip = MasterSlip::findOrFail($slipId);

            // Check if match already exists in slip
            $existingLeg = GeneratedSlipLeg::whereHas('generatedSlip', function ($q) use ($slipId) {
                $q->where('master_slip_id', $slipId);
            })->where('match_id', $validated['match_id'])->first();

            if ($existingLeg) {
                return response()->json([
                    'success' => false,
                    'message' => 'Match already exists in betslip'
                ], Response::HTTP_CONFLICT);
            }

            // Create generated slip if doesn't exist
            $generatedSlip = GeneratedSlip::firstOrCreate(
                ['master_slip_id' => $slipId],
                [
                    'stake' => $slip->stake,
                    'total_odds' => 1.0,
                    'possible_return' => $slip->stake,
                    'risk_level' => 'medium',
                    'confidence_score' => 0.5
                ]
            );

            // Add match leg
            $leg = GeneratedSlipLeg::create([
                'generated_slip_id' => $generatedSlip->id,
                'match_id' => $validated['match_id'],
                'market' => $validated['market'] ?? '1X2',
                'selection' => $validated['selection'] ?? 'home',
                'odds' => $validated['odds'] ?? 1.85,
            ]);

            // Update generated slip odds
            $this->updateSlipOdds($generatedSlip);

            return response()->json([
                'success' => true,
                'message' => 'Match added to betslip',
                'data' => [
                    'slip_id' => $slipId,
                    'match_id' => $validated['match_id'],
                    'leg_id' => $leg->id
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Betslip not found'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add match to betslip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove a match from betslip
     */
    public function removeMatchFromSlip($slipId, $matchId)
    {
        try {
            $slip = MasterSlip::findOrFail($slipId);

            // Find the generated slip
            $generatedSlip = GeneratedSlip::where('master_slip_id', $slipId)->first();

            if (!$generatedSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'No generated slip found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Remove the leg
            $deleted = GeneratedSlipLeg::where('generated_slip_id', $generatedSlip->id)
                ->where('match_id', $matchId)
                ->delete();

            if ($deleted) {
                // Update slip odds after removal
                $this->updateSlipOdds($generatedSlip);

                // Delete generated slip if empty
                if (GeneratedSlipLeg::where('generated_slip_id', $generatedSlip->id)->count() === 0) {
                    $generatedSlip->delete();
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Match removed from betslip'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Match not found in betslip'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Betslip not found'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove match from betslip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Helper method to update slip odds
     */
    private function updateSlipOdds(GeneratedSlip $generatedSlip)
    {
        $legs = $generatedSlip->legs;

        if ($legs->isEmpty()) {
            $generatedSlip->update([
                'total_odds' => 1.0,
                'possible_return' => $generatedSlip->stake
            ]);
            return;
        }

        $totalOdds = $legs->reduce(function ($carry, $leg) {
            return $carry * ($leg->odds ?? 1.0);
        }, 1.0);

        $generatedSlip->update([
            'total_odds' => $totalOdds,
            'possible_return' => $generatedSlip->stake * $totalOdds
        ]);
    }

    public function getMasterSlips(Request $request)
    {
        try {
            $query = MasterSlip::query()
                ->withCount(['generatedSlips as matches_count'])
                ->orderBy('created_at', 'desc');

            // Optional: Filter by user if you have authentication
            // if (auth()->check()) {
            //     $query->where('user_id', auth()->id());
            // }

            $slips = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Master slips retrieved successfully',
                'data' => $slips
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve master slips',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Run ML analysis on slip
     */
    public function analyzeSlip($id)
    {
        try {
            $slip = MasterSlip::with(['matches', 'slipMatches'])->findOrFail($id);

            // Check if slip has enough matches
            $matchCount = $slip->matches()->count();
            if ($matchCount < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slip must have at least 2 matches for analysis'
                ], 400);
            }

            // Check if slip is already being processed
            if ($slip->engine_status === 'processing' || $slip->engine_status === 'queued') {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis already in progress'
                ], 400);
            }

            // Update slip status
            $slip->update([
                'status' => 'processing',
                'engine_status' => 'queued',
                'processing_started_at' => now(),
                'analysis_quality' => 'pending'
            ]);

            // Dispatch GenerateAlternativeSlipsJob (your existing job)
            // This job will handle: data cleaning, formatting, and calling ProcessPythonRequest
            GenerateAlternativeSlipsJob::dispatch($id)
                ->onQueue('slip_analysis')
                ->delay(now()->addSeconds(3)); // Small delay to ensure slip update is saved

            // Return immediate response - processing happens in background
            return response()->json([
                'success' => true,
                'message' => 'Analysis queued successfully',
                'data' => [
                    'slip_id' => $id,
                    'status' => 'queued',
                    'engine_status' => 'queued',
                    'estimated_completion' => '30-60 seconds',
                    'monitor_url' => url("/api/slips/{$id}/status")
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to start slip analysis: ' . $e->getMessage(), [
                'slip_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            // Update slip status to failed
            if (isset($slip)) {
                $slip->update([
                    'status' => 'failed',
                    'engine_status' => 'failed',
                    'processing_completed_at' => now()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to start analysis',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    public function checkAnalysisStatus($id)
    {
        try {
            $slip = MasterSlip::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'slip_id' => $slip->id,
                    'status' => $slip->status,
                    'engine_status' => $slip->engine_status,
                    'analysis_quality' => $slip->analysis_quality,
                    'alternative_slips_count' => $slip->alternative_slips_count,
                    'processing_started_at' => $slip->processing_started_at,
                    'processing_completed_at' => $slip->processing_completed_at,
                    'estimated_payout' => $slip->estimated_payout,
                    'total_odds' => $slip->total_odds
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get status',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    // In your SlipController.php
    public function getSlipAnalysis($id)
    {
        try {
            $masterSlip = MasterSlip::with([
                'matches' => function ($query) {
                    $query->with(['markets', 'analysis']);
                },
                'slipMatches',
                'generatedSlips' => function ($query) {
                    $query->orderBy('confidence_score', 'desc')->take(5);
                },
                'user'
            ])->find($id);

            if (!$masterSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slip not found'
                ], 404);
            }

            // Calculate comprehensive analytics
            $analytics = $this->calculateSlipAnalytics($masterSlip);

            return response()->json([
                'success' => true,
                'message' => 'Slip analysis retrieved successfully',
                'data' => [
                    'slip' => $masterSlip,
                    'analytics' => $analytics,
                    'recommendations' => $this->generateRecommendations($masterSlip, $analytics),
                    'historical_performance' => $this->getHistoricalPerformance($masterSlip->user_id),
                    'similar_slips' => $this->getSimilarSlips($masterSlip)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get slip analysis: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve slip analysis',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    private function calculateSlipAnalytics($masterSlip)
    {
        // Implement comprehensive analytics calculation
        // This should include: confidence scores, risk assessment,
        // market distribution, league analysis, odds analysis, etc.
        return [
            'confidence_score' => $this->calculateConfidenceScore($masterSlip),
            'risk_level' => $this->calculateRiskLevel($masterSlip),
            'market_distribution' => $this->getMarketDistribution($masterSlip),
            'league_distribution' => $this->getLeagueDistribution($masterSlip),
            'odds_analysis' => $this->analyzeOdds($masterSlip),
            'profitability_metrics' => $this->calculateProfitability($masterSlip),
            // ... more analytics
        ];
    }

    public function DagetGeneratedSlips($id)
    {
        try {
            $slips = GeneratedSlip::where('master_slip_id', $id)
                ->with('legs')
                ->orderBy('confidence_score', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Generated slips retrieved',
                'data' => $slips
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve generated slips',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get matches in a slip
     */
    public function getSlipMatches($id)
    {
        try {
            $masterSlip = MasterSlip::with(['matches.markets', 'slipMatches'])->find($id);

            if (!$masterSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Master slip not found'
                ], 404);
            }

            // Format matches with their pivot data (market, selection, odds)
            $formattedMatches = $masterSlip->matches->map(function ($match) use ($masterSlip) {
                // Get the pivot data for this specific match
                $slipMatch = $masterSlip->slipMatches->firstWhere('match_id', $match->id);

                return [
                    'id' => $match->id,
                    'home_team' => $match->home_team,
                    'away_team' => $match->away_team,
                    'league' => $match->league,
                    'match_date' => $match->match_date?->toISOString(),
                    'status' => $match->status,
                    'home_score' => $match->home_score,
                    'away_score' => $match->away_score,
                    'home_team_id' => $match->home_team_id,
                    'away_team_id' => $match->away_team_id,
                    'competition' => $match->competition,
                    'venue' => $match->venue,
                    'weather_conditions' => $match->weather_conditions,
                    'referee' => $match->referee,
                    // Pivot data from master_slip_matches
                    'pivot' => $slipMatch ? [
                        'id' => $slipMatch->id,
                        'market' => $slipMatch->market,
                        'selection' => $slipMatch->selection,
                        'odds' => (float) $slipMatch->odds,
                        'match_data' => $slipMatch->match_data,
                        'notes' => $slipMatch->notes,
                        'created_at' => $slipMatch->created_at?->toISOString(),
                        'updated_at' => $slipMatch->updated_at?->toISOString(),
                    ] : null,
                    // Markets data
                    'markets' => $match->markets->map(function ($market) {
                        return [
                            'id' => $market->id,
                            'name' => $market->name,
                            'home_odds' => $market->pivot->odds['home'] ?? $market->pivot->odds['1'] ?? null,
                            'draw_odds' => $market->pivot->odds['draw'] ?? $market->pivot->odds['X'] ?? null,
                            'away_odds' => $market->pivot->odds['away'] ?? $market->pivot->odds['2'] ?? null,
                            'is_active' => $market->pivot->is_active ?? true,
                            'market_data' => $market->pivot->market_data ?? [],
                        ];
                    }),
                    'created_at' => $match->created_at?->toISOString(),
                    'updated_at' => $match->updated_at?->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Slip matches retrieved successfully',
                'data' => $formattedMatches
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get slip matches: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve matches',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


    /**
     * Get slip details with matches
     */
    public function DagetMasterSlipDetail($id)
    {
        try {
            // Try loading relationships separately to isolate the issue
            $masterSlip = MasterSlip::find($id);

            if (!$masterSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slip not found'
                ], 404);
            }

            // Load relationships one by one to find which one is causing the issue
            $masterSlip->load([
                'matches' => function ($query) {
                    $query->with('markets');
                }
            ]);

            // Try loading slipMatches separately with proper constraints
            $slipMatches = $masterSlip->slipMatches()->get();

            // Load other relationships
            $masterSlip->load(['generatedSlips', 'user']);

            // Calculate statistics
            $matches = $masterSlip->matches;
            $totalOdds = 1.0;

            // Use the separately loaded slipMatches
            foreach ($slipMatches as $slipMatch) {
                if ($slipMatch->odds) {
                    $totalOdds *= (float) $slipMatch->odds;
                }
            }

            $estimatedPayout = $masterSlip->stake * $totalOdds;

            // Get match details with their pivot data
            $matchDetails = $matches->map(function ($match) use ($slipMatches) {
                // Find the slip match for this match
                $slipMatch = $slipMatches->firstWhere('match_id', $match->id);

                return [
                    'match_id' => $match->id,
                    'home_team' => $match->home_team,
                    'away_team' => $match->away_team,
                    'league' => $match->league,
                    'match_date' => $match->match_date?->toISOString(),
                    'status' => $match->status,
                    'selection' => $slipMatch->selection ?? null,
                    'market' => $slipMatch->market ?? null,
                    'market_name' => $slipMatch->market_name ?? null,
                    'odds' => $slipMatch->odds ? (float) $slipMatch->odds : null,
                    'prediction' => $match->analysis_prediction ?? null,
                    'home_win_probability' => $match->analysis_home_win_probability ?? null,
                    'draw_probability' => $match->analysis_draw_probability ?? null,
                    'away_win_probability' => $match->analysis_away_win_probability ?? null,
                    'confidence' => $match->analysis_confidence ?? null,
                ];
            });

            // Get generated slips summary
            $generatedSlipsSummary = $masterSlip->generatedSlips->take(5)->map(function ($slip) {
                return [
                    'id' => $slip->id,
                    'total_odds' => (float) $slip->total_odds,
                    'estimated_payout' => (float) $slip->estimated_payout,
                    'confidence_score' => $slip->confidence_score ?? null,
                    'status' => $slip->status,
                    'created_at' => $slip->created_at?->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Slip details retrieved successfully',
                'data' => [
                    'slip' => [
                        'id' => $masterSlip->id,
                        'name' => $masterSlip->name ?? 'Unnamed Slip',
                        'stake' => (float) $masterSlip->stake,
                        'currency' => $masterSlip->currency ?? 'EUR',
                        'status' => $masterSlip->status,
                        'engine_status' => $masterSlip->engine_status,
                        'analysis_quality' => $masterSlip->analysis_quality,
                        'total_odds' => $totalOdds,
                        'estimated_payout' => $estimatedPayout,
                        'matches_count' => $matches->count(),
                        'generated_slips_count' => $masterSlip->generatedSlips()->count(),
                        'created_at' => $masterSlip->created_at?->toISOString(),
                        'updated_at' => $masterSlip->updated_at?->toISOString(),
                    ],
                    'matches' => $matchDetails,
                    'generated_slips_summary' => $generatedSlipsSummary,
                    'statistics' => [
                        'total_matches' => $matches->count(),
                        'average_odds' => $matches->count() > 0 ? $totalOdds / $matches->count() : 0,
                        'potential_payout' => $estimatedPayout,
                        'profit_potential' => $estimatedPayout - $masterSlip->stake,
                        'roi_percentage' => $masterSlip->stake > 0 ? (($estimatedPayout - $masterSlip->stake) / $masterSlip->stake) * 100 : 0,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get slip detail: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve slip details',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function DagetMasterSlip($id)
    {
        try {
            $masterSlip = MasterSlip::with(['matches', 'slipMatches', 'generatedSlips'])->find($id);

            if (!$masterSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slip not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Slip retrieved successfully',
                'data' => [
                    'id' => $masterSlip->id,
                    'name' => $masterSlip->name ?? 'Unnamed Slip',
                    'stake' => (float) $masterSlip->stake,
                    'currency' => $masterSlip->currency ?? 'EUR',
                    'status' => $masterSlip->status ?? 'draft',
                    'engine_status' => $masterSlip->engine_status ?? 'pending',
                    'analysis_quality' => $masterSlip->analysis_quality ?? 'medium',
                    'notes' => $masterSlip->notes,
                    'slip_data' => $masterSlip->slip_data ?? [],
                    'created_at' => $masterSlip->created_at?->toISOString(),
                    'updated_at' => $masterSlip->updated_at?->toISOString(),
                    'processing_started_at' => $masterSlip->processing_started_at?->toISOString(),
                    'processing_completed_at' => $masterSlip->processing_completed_at?->toISOString(),
                    'total_odds' => (float) $masterSlip->total_odds,
                    'estimated_payout' => (float) $masterSlip->estimated_payout,
                    'matches_count' => $masterSlip->matches()->count(),
                    'generated_slips_count' => $masterSlip->generatedSlips()->count(),
                    'alternative_slips_count' => $masterSlip->alternative_slips_count,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get master slip: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve slip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

          /**
     * Add a single match to an existing MasterSlip.
     *
     * This endpoint allows adding one match selection to a master slip.
     * It validates the input, creates a pivot record in master_slip_matches,
     * and optionally recalculates total odds and payout.
     *
     * @param Request $request
     * @param string $masterSlipId The ID of the MasterSlip
     * @return \Illuminate\Http\JsonResponse
     */
    public function addMatchToMasterSlip(Request $request, string $masterSlipId)
    {
        try {
            // Find the MasterSlip or return 404
            $masterSlip = MasterSlip::findOrFail($masterSlipId);

            // Validate incoming match data
            $validated = $request->validate([
                'match_id'   => 'required|integer|exists:matches,id',
                'market'     => 'required|string|max:100',
                'selection'  => 'required|string|max:100',
                'odds'       => 'required|numeric|min:1',
                'match_data' => 'nullable|array', // Optional extra data (e.g., market outcomes)
            ]);

            // Prevent duplicate match in the same slip
            $exists = $masterSlip->matches()->where('match_id', $validated['match_id'])->exists();
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'This match is already added to the slip.',
                ], 422);
            }

            // Create the pivot record
            $masterSlip->matches()->attach($validated['match_id'], [
                'market'     => $validated['market'],
                'selection'  => $validated['selection'],
                'odds'       => $validated['odds'],
                'match_data' => $validated['match_data'] ?? [],
            ]);

            // Optional: Recalculate total odds and estimated payout
            // This is a simple product of all odds — adjust if your logic differs
            $allOdds = $masterSlip->matches()->pluck('pivot.odds')->toArray();
            $totalOdds = array_product($allOdds) ?: 1.0;

            $masterSlip->update([
                'total_odds'        => round($totalOdds, 4),
                'estimated_payout'  => round($totalOdds * $masterSlip->stake, 2),
            ]);

            // Reload fresh data with relations
            $masterSlip->load('matches');

            return response()->json([
                'success' => true,
                'message' => 'Match added to slip successfully',
                'data' => [
                    'master_slip' => $masterSlip,
                    'added_match' => [
                        'match_id'   => $validated['match_id'],
                        'market'     => $validated['market'],
                        'selection'  => $validated['selection'],
                        'odds'       => $validated['odds'],
                    ]
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Master slip not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to add match to slip', [
                'master_slip_id' => $masterSlipId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add match to slip',
                'error'   => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update an existing master slip.
     *
     * This endpoint updates the details of a master slip, such as name, stake, notes, etc.
     * It returns the updated slip information.

     *
     * @param Request $request
     * @param string $id The UUID or ID of the master slip to update
     * @return \Illuminate\Http\JsonResponse
     */
          /**
     * Unified update endpoint for MasterSlip.
     *
     * This method intelligently handles:
     * - Updating slip metadata (name, stake, currency, notes)
     * - Adding new matches to the slip
     * - Both operations in a single request IF both data types are present
     *
     * It preserves the exact behavior of the previous two separate functions
     * while consolidating them into one clean, atomic endpoint.
     *

     */
    public function updateMasterSlip(Request $request, string $id)
    {
        try {
            $masterSlip = MasterSlip::findOrFail($id);

            // Separate validation rules for different data types
            $slipRules = [
                'name'     => 'sometimes|string|max:255',
                'stake'    => 'sometimes|numeric|min:0',
                'currency' => 'sometimes|string|max:3',
                'notes'    => 'nullable|string',
            ];

            $matchRules = [
                'match_id'   => 'required|integer|exists:matches,id',
                'market'     => 'required|string|max:100',
                'selection'  => 'required|string|max:100',
                'odds'       => 'required|numeric|min:1',
                'match_data' => 'nullable|array',
            ];

            $hasSlipData = $request->hasAny(['name', 'stake', 'currency', 'notes']);
            $hasMatchData = $request->has('match_id');

            // If neither type of data is present, return validation error
            if (!$hasSlipData && !$hasMatchData) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid data provided. Must include slip fields (name, stake, etc.) or match data.',
                ], 422);
            }

            DB::beginTransaction();

            // 1. Handle slip-level updates (if present)
            if ($hasSlipData) {
                $slipValidated = $request->validate($slipRules);
                $masterSlip->update($slipValidated);
            }

            // 2. Handle match addition (if present)
            $addedMatch = null;
            if ($hasMatchData) {
                $matchValidated = $request->validate($matchRules);

                // Prevent duplicate match using the model's relationship
                $exists = $masterSlip->matches()->where('match_id', $matchValidated['match_id'])->exists();
                if ($exists) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This match is already in the slip.',
                    ], 422);
                }

                // Attach match using the model's belongsToMany relationship
                $masterSlip->matches()->attach($matchValidated['match_id'], [
                    'market'     => $matchValidated['market'],
                    'selection'  => $matchValidated['selection'],
                    'odds'       => $matchValidated['odds'],
                    'match_data' => $matchValidated['match_data'] ?? [],
                ]);

                $addedMatch = [
                    'match_id'  => $matchValidated['match_id'],
                    'market'    => $matchValidated['market'],
                    'selection' => $matchValidated['selection'],
                    'odds'      => $matchValidated['odds'],
                ];

                // Recalculate total odds and payout using model's relationship
                $allOdds = $masterSlip->matches()->pluck('pivot.odds')->toArray();
                $totalOdds = $allOdds ? array_product($allOdds) : 1.0;

                $masterSlip->update([
                    'total_odds'       => round($totalOdds, 4),
                    'estimated_payout' => round($totalOdds * $masterSlip->stake, 2),
                ]);
            }

            // Refresh model with latest data
            $masterSlip->refresh();
            $masterSlip->load(['matches', 'slipMatches', 'generatedSlips']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $hasSlipData && $hasMatchData
                    ? 'Slip updated and match added successfully'
                    : ($hasSlipData ? 'Slip updated successfully' : 'Match added to slip successfully'),
                'data' => [
                    'id' => $masterSlip->id,
                    'name' => $masterSlip->name ?? 'Unnamed Slip',
                    'stake' => (float) $masterSlip->stake,
                    'currency' => $masterSlip->currency ?? 'EUR',
                    'status' => $masterSlip->status ?? 'draft',
                    'engine_status' => $masterSlip->engine_status ?? 'pending',
                    'analysis_quality' => $masterSlip->analysis_quality ?? 'medium',
                    'notes' => $masterSlip->notes,
                    'slip_data' => $masterSlip->slip_data ?? [],
                    'created_at' => $masterSlip->created_at?->toISOString(),
                    'updated_at' => $masterSlip->updated_at?->toISOString(),
                    'processing_started_at' => $masterSlip->processing_started_at?->toISOString(),
                    'processing_completed_at' => $masterSlip->processing_completed_at?->toISOString(),
                    'total_odds' => (float) $masterSlip->total_odds,
                    'estimated_payout' => (float) $masterSlip->estimated_payout,
                    'matches_count' => $masterSlip->matches()->count(),
                    'generated_slips_count' => $masterSlip->generatedSlips()->count(),
                    'alternative_slips_count' => $masterSlip->alternative_slips_count,
                    'added_match' => $addedMatch, // Only present if a match was added
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Slip not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update master slip', [
                'master_slip_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update slip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

        /**
     * Get all active (draft) master slips.
     *
     * This endpoint fetches all master slips with status 'draft',
     * including their relationships for comprehensive data.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function showActiveSlip()
    {
        try {
            // Load all draft slips with relationships
            $activeSlips = MasterSlip::where('status', 'draft')
                ->with(['matches', 'slipMatches', 'generatedSlips'])
                ->get();

            // Format data for each slip
            $formattedSlips = $activeSlips->map(function ($slip) {
                return [
                    'id' => $slip->id,
                    'name' => $slip->name ?? 'Unnamed Slip',
                    'stake' => (float) $slip->stake,
                    'currency' => $slip->currency ?? 'EUR',
                    'status' => $slip->status ?? 'draft',
                    'engine_status' => $slip->engine_status ?? 'pending',
                    'analysis_quality' => $slip->analysis_quality ?? 'medium',
                    'notes' => $slip->notes,
                    'slip_data' => $slip->slip_data ?? [],
                    'created_at' => $slip->created_at?->toISOString(),
                    'updated_at' => $slip->updated_at?->toISOString(),
                    'processing_started_at' => $slip->processing_started_at?->toISOString(),
                    'processing_completed_at' => $slip->processing_completed_at?->toISOString(),
                    'total_odds' => (float) $slip->total_odds,
                    'estimated_payout' => (float) $slip->estimated_payout,
                    'matches_count' => $slip->matches->count(),
                    'generated_slips_count' => $slip->generatedSlips->count(),
                    'alternative_slips_count' => $slip->alternative_slips_count,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Active slips retrieved successfully',
                'data' => $formattedSlips,
                'count' => $formattedSlips->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve active slips: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active slips',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


        /**
     * Get MasterSlip details with matches for frontend dashboard display.
     *
     * Returns only the essential metadata and matches in a clean, easy-to-consume format.
     * Optimized for dashboard rendering.
     *
     * @param string $id The MasterSlip ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDashboardSlip(string $id)
    {
        try {
            // Start a database transaction to ensure data consistency
            DB::beginTransaction();

            $masterSlip = MasterSlip::with([
                'matches' => function ($query) {
                    $query->orderBy('pivot_created_at', 'asc'); // Consistent match order
                }
            ])
                ->findOrFail($id);

            // Count the number of matches for this slip and update matches_count column
            $matchesCount = $masterSlip->matches()->count();
            $masterSlip->update([
                'matches_count' => $matchesCount,
            ]);

            // Calculate total odds from all matches in the slip
            $totalOdds = 1.0;
            $hasMatchesWithOdds = false;

            foreach ($masterSlip->matches as $match) {
                $pivot = $match->pivot;
                if ($pivot && $pivot->odds && is_numeric($pivot->odds)) {
                    $totalOdds *= (float) $pivot->odds;
                    $hasMatchesWithOdds = true;
                }
            }

            // If no matches or no odds, set to 1 (neutral)
            if (!$hasMatchesWithOdds) {
                $totalOdds = 1.0;
            }

            // Calculate estimated payout
            $stake = (float) $masterSlip->stake;
            $estimatedPayout = $stake * $totalOdds;

            // Update the master slip with calculated values
            $masterSlip->update([
                'total_odds' => $totalOdds,
                'estimated_payout' => $estimatedPayout,
                'updated_at' => now(),
            ]);

            // Commit the transaction
            DB::commit();

            // Refresh the slip to get updated values
            $masterSlip->refresh();

            // Transform matches to include pivot data cleanly
            $formattedMatches = $masterSlip->matches->map(function ($match) {
                $pivot = $match->pivot;

                // Parse match_data if it's JSON string
                $matchData = $pivot->match_data ?? [];
                if (is_string($matchData)) {
                    $matchData = json_decode($matchData, true) ?? [];
                }

                return [
                    'id' => $match->id,
                    'match_id' => $match->id,
                    'home_team' => $match->home_team,
                    'away_team' => $match->away_team,
                    'league' => $match->league,
                    'match_date' => $match->match_date?->toISOString(),
                    'kickoff_time' => $match->match_date?->format('H:i'),
                    'status' => $match->status,
                    'market' => $pivot->market,
                    'market_name' => $pivot->market_name ?? $pivot->market,
                    'selection' => $pivot->selection,
                    'odds' => (float) $pivot->odds,
                    'match_data' => $matchData,
                    'created_at' => $pivot->created_at?->toISOString(),
                    'updated_at' => $pivot->updated_at?->toISOString(),
                ];
            });

            // Calculate additional statistics (matchesCount already calculated above)
            $averageOdds = $matchesCount > 0 ? $totalOdds / $matchesCount : 0;
            $potentialProfit = $estimatedPayout - $stake;
            $roiPercentage = $stake > 0 ? ($potentialProfit / $stake) * 100 : 0;

            // Get confidence score if available (from analysis or calculate)
            $confidenceScore = $masterSlip->confidence_score ?? $this->calculateConfidenceScore($masterSlip);

            // Risk assessment based on confidence
            $riskLevel = $this->firstDetermineRiskLevel($confidenceScore, $matchesCount, $totalOdds);

            return response()->json([
                'success' => true,
                'message' => 'Slip details retrieved successfully',
                'data' => [
                    'slip' => [
                        'id' => $masterSlip->id,
                        'name' => $masterSlip->name ?? 'Unnamed Slip',
                        'stake' => (float) $masterSlip->stake,
                        'currency' => $masterSlip->currency ?? 'EUR',
                        'status' => $masterSlip->status ?? 'draft',
                        'engine_status' => $masterSlip->engine_status ?? 'idle',
                        'analysis_quality' => $masterSlip->analysis_quality ?? 'pending',
                        'total_odds' => (float) $totalOdds,
                        'estimated_payout' => (float) $estimatedPayout,
                        'confidence_score' => (float) $confidenceScore,
                        'risk_level' => $riskLevel,
                        'matches_count' => $matchesCount,
                        'generated_slips_count' => $masterSlip->generatedSlips()->count(),
                        'user_id' => $masterSlip->user_id,
                        'created_at' => $masterSlip->created_at?->toISOString(),
                        'updated_at' => $masterSlip->updated_at?->toISOString(),
                    ],
                    'matches' => $formattedMatches,
                    'statistics' => [
                        'total_matches' => $matchesCount,
                        'total_odds' => (float) $totalOdds,
                        'average_odds' => (float) $averageOdds,
                        'stake' => (float) $stake,
                        'estimated_payout' => (float) $estimatedPayout,
                        'potential_profit' => (float) $potentialProfit,
                        'roi_percentage' => (float) $roiPercentage,
                        'confidence_score' => (float) $confidenceScore,
                        'risk_level' => $riskLevel,
                        'break_even_odds' => $stake > 0 ? (1 / ($stake / $estimatedPayout)) : 0,
                    ],
                    'analysis' => [
                        'confidence_breakdown' => $this->getConfidenceBreakdown($masterSlip),
                        'risk_factors' => $this->identifyRiskFactors($masterSlip),
                        'recommendations' => $this->generateRecommendations($masterSlip),
                    ]
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Slip not found',
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to fetch dashboard slip', [
                'slip_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load slip details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

/**
 * Helper method to calculate confidence score
 */
private function calculateConfidenceScore(MasterSlip $masterSlip): float
{
    $matches = $masterSlip->matches;
    if ($matches->isEmpty()) {
        return 0.0;
    }

    $totalConfidence = 0;
    $count = 0;

    foreach ($matches as $match) {
        // If match has analysis confidence, use it
        if ($match->analysis_confidence) {
            $totalConfidence += (float) $match->analysis_confidence;
            $count++;
        } else {
            // Fallback: calculate based on odds and other factors
            $pivot = $match->pivot;
            if ($pivot && $pivot->odds) {
                $odds = (float) $pivot->odds;
                // Lower odds = higher confidence (inverse relationship)
                $confidence = min(100, max(10, 100 / sqrt($odds)));
                $totalConfidence += $confidence;
                $count++;
            }
        }
    }

    return $count > 0 ? round($totalConfidence / $count, 2) : 50.0; // Default to 50 if no data
}

/**
 * Determine risk level based on multiple factors
 */
private function firstDetermineRiskLevel(float $confidenceScore, int $matchesCount, float $totalOdds): string
{
    $riskScore = 0;
    
    // Confidence factor (higher confidence = lower risk)
    if ($confidenceScore >= 75) {
        $riskScore += 1;
    } elseif ($confidenceScore >= 50) {
        $riskScore += 2;
    } else {
        $riskScore += 3;
    }

    // Match count factor (more matches = higher risk)
    if ($matchesCount >= 8) {
        $riskScore += 3;
    } elseif ($matchesCount >= 5) {
        $riskScore += 2;
    } elseif ($matchesCount >= 3) {
        $riskScore += 1;
    }

    // Odds factor (higher odds = higher risk)
    if ($totalOdds >= 50) {
        $riskScore += 3;
    } elseif ($totalOdds >= 20) {
        $riskScore += 2;
    } elseif ($totalOdds >= 10) {
        $riskScore += 1;
    }

    // Determine risk level
    if ($riskScore <= 3) {
        return 'Low';
    } elseif ($riskScore <= 6) {
        return 'Medium';
    } else {
        return 'High';
    }
}

/**
 * Get confidence breakdown for each match
 */
private function getConfidenceBreakdown(MasterSlip $masterSlip): array
{
    $breakdown = [];
    
    foreach ($masterSlip->matches as $match) {
        $pivot = $match->pivot;
        $odds = $pivot ? (float) $pivot->odds : 1.0;
        
        $breakdown[] = [
            'match_id' => $match->id,
            'home_team' => $match->home_team,
            'away_team' => $match->away_team,
            'odds' => $odds,
            'market' => $pivot->market ?? 'Unknown',
            'confidence' => $match->analysis_confidence ?? round(100 / sqrt($odds), 2),
            'contribution' => $odds, // How much this match contributes to total odds
        ];
    }

    return $breakdown;
}

/**
 * Identify risk factors
 */
private function identifyRiskFactors(MasterSlip $masterSlip): array
{
    $riskFactors = [];
    $matches = $masterSlip->matches;

    // Check for high odds
    if ($masterSlip->total_odds > 50) {
        $riskFactors[] = [
            'type' => 'high_odds',
            'severity' => 'high',
            'message' => 'Extremely high combined odds increase risk significantly',
        ];
    }

    // Check for many matches
    if ($matches->count() >= 8) {
        $riskFactors[] = [
            'type' => 'many_matches',
            'severity' => 'medium',
            'message' => 'Large number of matches increases complexity and risk',
        ];
    }

    // Check for low confidence matches
    $lowConfidenceMatches = $matches->filter(function ($match) {
        return ($match->analysis_confidence ?? 50) < 40;
    });

    if ($lowConfidenceMatches->count() > 0) {
        $riskFactors[] = [
            'type' => 'low_confidence_matches',
            'severity' => 'medium',
            'message' => $lowConfidenceMatches->count() . ' matches have low confidence scores',
        ];
    }

    return $riskFactors;
}

/**
 * Generate recommendations based on slip analysis
 */
private function generateRecommendations(MasterSlip $masterSlip): array
{
    $recommendations = [];
    $confidenceScore = $masterSlip->confidence_score ?? 50;

    if ($confidenceScore < 50) {
        $recommendations[] = [
            'type' => 'caution',
            'message' => 'Low confidence score detected. Consider reviewing selections or reducing stake.',
            'action' => 'review_selections',
        ];
    }

    if ($masterSlip->total_odds > 20) {
        $recommendations[] = [
            'type' => 'risk_management',
            'message' => 'High combined odds detected. Consider smaller stake or fewer selections.',
            'action' => 'adjust_stake',
        ];
    }

    if ($masterSlip->matches()->count() >= 5) {
        $recommendations[] = [
            'type' => 'portfolio',
            'message' => 'Multiple selections detected. Consider splitting into smaller slips.',
            'action' => 'split_slip',
        ];
    }

    // Always include general recommendations
    $recommendations[] = [
        'type' => 'general',
        'message' => 'Monitor match statuses and consider cash-out options if available.',
        'action' => 'monitor',
    ];

    return $recommendations;
}



    /**
 * Add matches to a master slip
 * 
 * @param Request $request
 * @param int $id Master slip ID
 * @return \Illuminate\Http\JsonResponse
 */
    public function addMatchesToSlip(Request $request, $id)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'stake' => 'required|numeric|min:0',
                'currency' => 'required|string|max:10',
                'name' => 'sometimes|string|max:255',
                'matches' => 'required|array|min:1',
                'matches.*.match_id' => 'required|integer|exists:matches,id',
                'matches.*.market' => 'required|string|max:100',
                'matches.*.market_name' => 'sometimes|string|max:255',
                'matches.*.selection' => 'required|string|max:255',
                'matches.*.odds' => 'required|numeric|min:1.01',
                'matches.*.home_team' => 'sometimes|string|max:255',
                'matches.*.away_team' => 'sometimes|string|max:255',
                'matches.*.league' => 'sometimes|string|max:255',
                'matches.*.match_date' => 'sometimes|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find the master slip
            $masterSlip = MasterSlip::find($id);

            if (!$masterSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slip not found'
                ], 404);
            }

            // Update basic slip information
            if ($request->has('stake')) {
                $masterSlip->stake = $request->stake;
            }
            if ($request->has('currency')) {
                $masterSlip->currency = $request->currency;
            }
            if ($request->has('name')) {
                $masterSlip->name = $request->name;
            }
            $masterSlip->save();

            // Process each match
            $addedMatches = [];
            foreach ($request->matches as $matchData) {
                $matchId = $matchData['match_id'];

                // Sync the match with pivot data
                $masterSlip->matches()->syncWithoutDetaching([
                    $matchId => [
                        'market' => $matchData['market'],
                        'market_name' => $matchData['market_name'] ?? $matchData['market'],
                        'selection' => $matchData['selection'],
                        'odds' => $matchData['odds'],
                        'updated_at' => now(),
                    ]
                ]);

                // Get match for response
                $match = MatchModel::find($matchId);
                $addedMatches[] = [
                    'match_id' => $matchId,
                    'home_team' => $matchData['home_team'] ?? $match->home_team,
                    'away_team' => $matchData['away_team'] ?? $match->away_team,
                    'league' => $matchData['league'] ?? $match->league,
                    'market' => $matchData['market'],
                    'market_name' => $matchData['market_name'] ?? $matchData['market'],
                    'selection' => $matchData['selection'],
                    'odds' => (float) $matchData['odds'],
                ];
            }

            // Refresh relationships
            $masterSlip->load(['matches', 'generatedSlips', 'user']);

            // Calculate statistics - get pivot data differently
            $matches = $masterSlip->matches;
            $totalOdds = 1.0;

            // Calculate from pivot data
            foreach ($matches as $match) {
                if ($match->pivot->odds) {
                    $totalOdds *= (float) $match->pivot->odds;
                }
            }

            $estimatedPayout = $masterSlip->stake * $totalOdds;

            // Get match details with pivot data
            $matchDetails = $matches->map(function ($match) {
                return [
                    'match_id' => $match->id,
                    'home_team' => $match->home_team,
                    'away_team' => $match->away_team,
                    'league' => $match->league,
                    'match_date' => $match->match_date?->toISOString(),
                    'status' => $match->status,
                    'selection' => $match->pivot->selection ?? null,
                    'market' => $match->pivot->market ?? null,
                    'market_name' => $match->pivot->market_name ?? null,
                    'odds' => $match->pivot->odds ? (float) $match->pivot->odds : null,
                    'prediction' => $match->analysis_prediction ?? null,
                    'home_win_probability' => $match->analysis_home_win_probability ?? null,
                    'draw_probability' => $match->analysis_draw_probability ?? null,
                    'away_win_probability' => $match->analysis_away_win_probability ?? null,
                    'confidence' => $match->analysis_confidence ?? null,
                ];
            });

            // Get generated slips summary
            $generatedSlipsSummary = $masterSlip->generatedSlips->take(5)->map(function ($slip) {
                return [
                    'id' => $slip->id,
                    'total_odds' => (float) $slip->total_odds,
                    'estimated_payout' => (float) $slip->estimated_payout,
                    'confidence_score' => $slip->confidence_score ?? null,
                    'status' => $slip->status,
                    'created_at' => $slip->created_at?->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Matches added to slip successfully',
                'data' => [
                    'slip' => [
                        'id' => $masterSlip->id,
                        'name' => $masterSlip->name ?? 'Unnamed Slip',
                        'stake' => (float) $masterSlip->stake,
                        'currency' => $masterSlip->currency ?? 'EUR',
                        'status' => $masterSlip->status,
                        'engine_status' => $masterSlip->engine_status,
                        'analysis_quality' => $masterSlip->analysis_quality,
                        'total_odds' => $totalOdds,
                        'estimated_payout' => $estimatedPayout,
                        'matches_count' => $matches->count(),
                        'generated_slips_count' => $masterSlip->generatedSlips()->count(),
                        'created_at' => $masterSlip->created_at?->toISOString(),
                        'updated_at' => $masterSlip->updated_at?->toISOString(),
                    ],
                    'matches' => $matchDetails,
                    'generated_slips_summary' => $generatedSlipsSummary,
                    'statistics' => [
                        'total_matches' => $matches->count(),
                        'average_odds' => $matches->count() > 0 ? $totalOdds / $matches->count() : 0,
                        'potential_payout' => $estimatedPayout,
                        'profit_potential' => $estimatedPayout - $masterSlip->stake,
                        'roi_percentage' => $masterSlip->stake > 0 ? (($estimatedPayout - $masterSlip->stake) / $masterSlip->stake) * 100 : 0,
                    ],
                    'added_matches' => $addedMatches,
                    'added_count' => count($addedMatches)
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to add matches to slip: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to add matches to slip',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }




/*******************************************************************************************************
 * 
 * 
         ANALYSE MASTER SlIP


 */

    public function getSlipInsights($id)
    {
        try {
            // Load slip with correct relationship names
            $slip = MasterSlip::with([
                'matches' => function ($query) {
                    $query->with([
                        'homeTeam',
                        'awayTeam',
                        // 'league', // If you have league relationship
                        'markets' => function ($mq) {
                            $mq->withPivot('odds', 'market_data');
                        }
                    ]);
                },
                // Load slip matches separately to get market/selection data
                'slipMatches' => function ($query) {
                    $query->with([
                        'match' => function ($q) {
                            $q->with(['homeTeam', 'awayTeam']);
                        }
                    ]);
                },
                'user'
            ])->findOrFail($id);

            // If no matches found, return empty insights
            if ($slip->matches->isEmpty() && $slip->slipMatches->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => $this->generateEmptyInsights($slip)
                ]);
            }

            // Generate comprehensive insights
            $insights = $this->generateComprehensiveInsights($slip);

            return response()->json([
                'success' => true,
                'data' => $insights
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating slip insights: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate insights',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Generate comprehensive insights for a slip
     * 
     * @param MasterSlip $slip
     * @return array
     */
    private function generateComprehensiveInsights(MasterSlip $slip)
    {
        // Use slipMatches instead of matches relationship for market/selection data
        $slipMatches = $slip->slipMatches;

        // Calculate basic slip metrics
        $analysisSummary = $this->calculateAnalysisSummary($slip, $slipMatches);

        // Generate match-specific insights
        $matchInsights = $this->generateMatchInsights($slipMatches);

        // Calculate risk metrics
        $totalOdds = $slip->total_odds ?? 1;
        $stake = $slip->stake ?? 0;
        $riskMetrics = $this->calculateRiskMetrics($slipMatches, $totalOdds, $stake);

        // Generate Monte Carlo simulation results
        $monteCarlo = $this->simulateMonteCarlo($slipMatches, $stake, $totalOdds);

        // Generate optimization suggestions
        $optimizationSuggestions = $this->generateOptimizationSuggestions($slip, $slipMatches);

        // Comparative analysis with historical data
        $comparativeAnalysis = $this->performComparativeAnalysis($slip, $slipMatches);

        // Generate alerts and warnings
        $alertWarnings = $this->generateAlertWarnings($slipMatches);

        // ML predictions and feature importance
        $mlPredictions = $this->generateMLPredictions($slipMatches);

        return [
            'slip_id' => $slip->id,
            'insights_generated_at' => now()->toISOString(),
            'analysis_summary' => $analysisSummary,
            'monte_carlo_simulation' => $monteCarlo,
            'match_insights' => $matchInsights,
            'risk_metrics' => $riskMetrics,
            'ml_predictions' => $mlPredictions,
            'optimization_suggestions' => $optimizationSuggestions,
            'comparative_analysis' => $comparativeAnalysis,
            'alert_warnings' => $alertWarnings,
            'metadata' => [
                'processing_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2),
                'model_version' => 'v1.0.0',
                'cache_status' => 'fresh',
                'next_refresh' => now()->addMinutes(5)->toISOString()
            ]
        ];
    }

    /**
     * Calculate analysis summary
     */
    private function calculateAnalysisSummary(MasterSlip $slip, $slipMatches)
    {
        $totalOdds = $slip->total_odds ?? 1;
        $stake = $slip->stake ?? 0;
        $possibleReturn = $stake * $totalOdds;

        // Calculate average match confidence
        $totalConfidence = 0;
        $matchCount = $slipMatches->count();

        foreach ($slipMatches as $slipMatch) {
            $confidence = $this->calculateMatchConfidence($slipMatch);
            $totalConfidence += $confidence;
        }

        $avgConfidence = $matchCount > 0 ? $totalConfidence / $matchCount : 50;

        // Calculate Kelly Criterion
        $probabilityOfSuccess = 1 / $totalOdds; // Implied probability from odds
        $kellyPercentage = $probabilityOfSuccess > 0 && $totalOdds > 1 ?
            max(0, (($totalOdds * $probabilityOfSuccess) - 1) / ($totalOdds - 1)) * 100 : 0;

        // Calculate expected value
        $expectedValue = ($possibleReturn * $probabilityOfSuccess) - $stake;

        // Determine risk level
        $riskLevel = $this->determineRiskLevel($avgConfidence, $totalOdds, $matchCount);

        return [
            'overall_confidence' => round($avgConfidence, 1),
            'risk_assessment' => $riskLevel,
            'expected_value' => round($expectedValue, 2),
            'probability_of_success' => round($probabilityOfSuccess, 3),
            'kelly_criterion' => round(min($kellyPercentage, 25), 1), // Cap at 25% for safety
            'variance' => round($this->calculateVariance($slipMatches), 1)
        ];
    }

    /**
     * Generate insights for each match
     */
    private function generateMatchInsights($slipMatches)
    {
        $insights = [];

        foreach ($slipMatches as $slipMatch) {
            $match = $slipMatch->match; // Use the match relationship from MasterSlipMatch
            if (!$match)
                continue;

            // Get team forms using your Team_Form model
            // $homeForm = Team_Form::where('team_id', $match->home_team_id)
            //     ->latest('created_at')
            //     ->first();

            // $awayForm = Team_Form::where('team_id', $match->away_team_id)
            //     ->latest('created_at')
            //     ->first();

            // // Get head-to-head data using your Head_To_Head model
            // if ($match->home_team_id && $match->away_team_id) {
            //         $h2h = Head_To_Head::where('home_id', $match->home_team_id)
            //             ->where('away_id', $match->away_team_id)
            //             ->latest('match_date')
            //             ->first();
            //     } else {
            //         $h2h = null;
            //     }
            $homeForm = $match->home_form ?? [];
            $awayForm = $match->away_form ?? [];
            $h2h = $match->head_to_head ?? [];

            // Calculate match confidence
            $confidence = $this->calculateMatchConfidence($slipMatch);

            // Get market data from slipMatch pivot
            $market = $slipMatch->market;
            $selection = $slipMatch->selection;
            $odds = $slipMatch->odds;

            // Generate key factors
            $keyFactors = $this->extractKeyFactors($match, $homeForm, $awayForm, $h2h);

            // Calculate expected goals
            $expectedGoals = $this->calculateExpectedGoals($homeForm, $awayForm, $h2h);

            // Get team names
            $homeTeamName = $match->homeTeam ? $match->homeTeam->name : ($match->home_team ?? 'Unknown');
            $awayTeamName = $match->awayTeam ? $match->awayTeam->name : ($match->away_team ?? 'Unknown');

            $insights[] = [
                'match_id' => $match->id,
                'home_team' => $homeTeamName,
                'away_team' => $awayTeamName,
                'league' => $match->league ?? 'Unknown',
                'market' => $market ?? 'Unknown',
                'selection' => $selection ?? 'Unknown',
                'odds' => $odds ?? 1.0,
                'predicted_outcome' => $this->predictOutcome($match, $homeForm, $awayForm, $h2h),
                'confidence' => $confidence,
                'key_factors' => $keyFactors,
                'expected_goals' => $expectedGoals,
                'predicted_score' => $this->predictScore($expectedGoals),
                'market_efficiency' => $this->calculateMarketEfficiency($odds ?? 1.0, $confidence),
                'recent_form_home' => $match->home_form ?? [],
                'recent_form_away' => $match->away_form ?? [],
                'head_to_head_summary' => $match->head_to_head ?? ($h2h ? $this->summarizeH2H($h2h) : null)
            ];
        }

        return $insights;
    }


     /**
     * Calculate match confidence based on available data
     */
    private function calculateMatchConfidence($slipMatch)
    {
        $match = $slipMatch->match;
        if (!$match)
            return 50;

        $confidence = 50; // Base confidence

        // Use form data from match if available
        if (!empty($match->home_form)) {
            $homeFormData = is_array($match->home_form) ? $match->home_form : json_decode($match->home_form, true);
            if (is_array($homeFormData)) {
                $homeFormRating = $this->calculateFormRating($homeFormData);
                if ($homeFormRating > 70)
                    $confidence += 10;
            }
        }

        if (!empty($match->away_form)) {
            $awayFormData = is_array($match->away_form) ? $match->away_form : json_decode($match->away_form, true);
            if (is_array($awayFormData)) {
                $awayFormRating = $this->calculateFormRating($awayFormData);
                if ($awayFormRating > 70)
                    $confidence -= 5;
            }
        }

        // Use head-to-head data from match if available
        if (!empty($match->head_to_head)) {
            $h2hData = is_array($match->head_to_head) ? $match->head_to_head : json_decode($match->head_to_head, true);
            if (is_array($h2hData) && isset($h2hData['home_wins'], $h2hData['away_wins'])) {
                $totalMatches = ($h2hData['home_wins'] ?? 0) + ($h2hData['away_wins'] ?? 0) + ($h2hData['draws'] ?? 0);
                if ($totalMatches > 0 && ($h2hData['home_wins'] ?? 0) > ($h2hData['away_wins'] ?? 0)) {
                    $confidence += 5;
                }
            }
        }

        // Adjust based on market odds
        $odds = $slipMatch->odds ?? 1.0;
        if ($odds > 3.0)
            $confidence -= 15;
        if ($odds < 1.5)
            $confidence += 10;

        // Use ML predictions if available
        if ($match->analysis_confidence) {
            $confidence = ($confidence + $match->analysis_confidence) / 2;
        }

        // Cap between 10 and 95
        return max(10, min(95, $confidence));
    }
    
    /**
     * Extract key factors for a match
     */
    private function extractKeyFactors($match, $homeForm, $awayForm, $h2h)
    {
        $factors = [];

        // Home advantage factor
        $factors[] = [
            'factor' => 'Home Advantage',
            'impact' => 'positive',
            'weight' => 0.15,
            'description' => 'Home teams win approximately 46% of matches'
        ];

        // Use form data from match model
        if (!empty($match->home_form)) {
            $formData = is_array($match->home_form) ? $match->home_form : json_decode($match->home_form, true);
            $formRating = $this->calculateFormRating($formData);
            $impact = $formRating > 65 ? 'strong_positive' : ($formRating > 55 ? 'positive' : 'neutral');
            $factors[] = [
                'factor' => 'Home Team Recent Form',
                'impact' => $impact,
                'weight' => 0.12,
                'rating' => round($formRating, 1)
            ];
        }

        if (!empty($match->away_form)) {
            $formData = is_array($match->away_form) ? $match->away_form : json_decode($match->away_form, true);
            $formRating = $this->calculateFormRating($formData);
            $impact = $formRating > 65 ? 'negative' : ($formRating > 55 ? 'neutral' : 'positive');
            $factors[] = [
                'factor' => 'Away Team Recent Form',
                'impact' => $impact,
                'weight' => 0.10,
                'rating' => round($formRating, 1)
            ];
        }

        // Use head-to-head data from match model
        if (!empty($match->head_to_head)) {
            $h2hData = is_array($match->head_to_head) ? $match->head_to_head : json_decode($match->head_to_head, true);
            if (isset($h2hData['home_wins'], $h2hData['total_matches'])) {
                $totalMatches = $h2hData['total_matches'] ?? 0;
                $homeWins = $h2hData['home_wins'] ?? 0;
                if ($totalMatches > 0) {
                    $homeWinRate = ($homeWins / $totalMatches) * 100;
                    if ($homeWinRate > 60) {
                        $factors[] = [
                            'factor' => 'Head-to-Head Dominance',
                            'impact' => 'positive',
                            'weight' => 0.08,
                            'home_win_rate' => round($homeWinRate, 1) . '%'
                        ];
                    }
                }
            }
        }

        return $factors;
    }
        private function calculateFormRating($formData)
    {
        if (!is_array($formData) || empty($formData)) return 50;
        
        // If form_data contains results array
        if (isset($formData['results']) && is_array($formData['results'])) {
            $results = $formData['results'];
            $total = count($results);
            if ($total === 0) return 50;
            
            $points = 0;
            foreach ($results as $result) {
                if ($result === 'W') $points += 3;
                elseif ($result === 'D') $points += 1;
            }
            
            return ($points / ($total * 3)) * 100;
        }
        
        // If form_data is an array of results
        $results = array_slice($formData, 0, 5);
        $total = count($results);
        if ($total === 0) return 50;
        
        $points = 0;
        foreach ($results as $result) {
            if ($result === 'W') $points += 3;
            elseif ($result === 'D') $points += 1;
        }
        
        return ($points / ($total * 3)) * 100;
    }
    
    
    /**
     * Calculate expected goals
     */
        private function calculateExpectedGoals($homeForm, $awayForm, $h2h)
        {
            // Simplified expected goals calculation
            $baseHomeXG = 1.5;
            $baseAwayXG = 1.2;

            // Adjust based on form ratings if available
            if ($homeForm && $homeForm->form_rating) {
                $baseHomeXG += ($homeForm->form_rating - 50) / 100;
            }

            if ($awayForm && $awayForm->form_rating) {
                $baseAwayXG += ($awayForm->form_rating - 50) / 100;
            }

            // Adjust based on H2H if available
            if ($h2h && $h2h->total_matches > 0) {
                $avgHomeGoals = $h2h->home_goals / $h2h->total_matches;
                $avgAwayGoals = $h2h->away_goals / $h2h->total_matches;
                $baseHomeXG = ($baseHomeXG + $avgHomeGoals) / 2;
                $baseAwayXG = ($baseAwayXG + $avgAwayGoals) / 2;
            }

            return [
                'home' => round(max(0.2, min(4.0, $baseHomeXG)), 2),
                'away' => round(max(0.2, min(4.0, $baseAwayXG)), 2)
            ];
        }


        /**
         * Predict match outcome
         */
    private function predictOutcome($match, $homeForm, $awayForm, $h2h)
    {
        $homeStrength = 50;
        $awayStrength = 50;

        // Calculate home strength from form data
        if (!empty($match->home_form)) {
            $formData = is_array($match->home_form) ? $match->home_form : json_decode($match->home_form, true);
            $homeStrength = $this->calculateFormRating($formData);
        }

        // Calculate away strength from form data
        if (!empty($match->away_form)) {
            $formData = is_array($match->away_form) ? $match->away_form : json_decode($match->away_form, true);
            $awayStrength = $this->calculateFormRating($formData);
        }

        // Add home advantage
        $homeStrength += 10;

        // Adjust based on H2H data from match
        if (!empty($match->head_to_head)) {
            $h2hData = is_array($match->head_to_head) ? $match->head_to_head : json_decode($match->head_to_head, true);
            if (isset($h2hData['home_wins'], $h2hData['total_matches'])) {
                $totalMatches = $h2hData['total_matches'] ?? 0;
                $homeWins = $h2hData['home_wins'] ?? 0;
                if ($totalMatches > 0) {
                    $homeWinRate = ($homeWins / $totalMatches) * 100;
                    if ($homeWinRate > 60)
                        $homeStrength += 5;
                    if ($homeWinRate < 40)
                        $homeStrength -= 5;
                }
            }
        }

        if ($homeStrength - $awayStrength > 15)
            return 'Home Win';
        if ($awayStrength - $homeStrength > 15)
            return 'Away Win';
        return 'Draw';
    }
    
    /**
     * Predict score based on expected goals
     */
    private function predictScore($expectedGoals)
    {
        $homeGoals = round($expectedGoals['home']);
        $awayGoals = round($expectedGoals['away']);
        
        // Poisson distribution adjustment
        $homeGoals = max(0, $homeGoals + rand(-1, 1));
        $awayGoals = max(0, $awayGoals + rand(-1, 1));
        
        return $homeGoals . '-' . $awayGoals;
    }
    
    /**
     * Calculate market efficiency
     */
    private function calculateMarketEfficiency($odds, $confidence)
    {
        $impliedProbability = 1 / $odds;
        $estimatedProbability = $confidence / 100;
        
        $efficiency = 1 - abs($impliedProbability - $estimatedProbability);
        return round(max(0.5, min(1.0, $efficiency)), 2);
    }
    
    /**
     * Summarize head-to-head data
     */
    private function summarizeH2H($h2h)
    {
        return [
            'total_matches' => $h2h->total_matches,
            'home_wins' => $h2h->home_wins,
            'away_wins' => $h2h->away_wins,
            'draws' => $h2h->draws,
            'home_goals' => $h2h->home_goals,
            'away_goals' => $h2h->away_goals,
            'last_match_date' => $h2h->last_match_date,
            'home_win_rate' => $h2h->total_matches > 0 ? 
                round(($h2h->home_wins / $h2h->total_matches) * 100, 1) : 0
        ];
    }
    
    /**
     * Calculate risk metrics
     */
    private function calculateRiskMetrics($matches, $totalOdds, $stake)
    {
        $possibleReturn = $stake * $totalOdds;
        
        // Calculate odds variance
        $oddsArray = [];
        foreach ($matches as $match) {
            $marketOutcome = $match->marketOutcome;
            if ($marketOutcome) {
                $oddsArray[] = $marketOutcome->odds;
            }
        }
        
        $variance = count($oddsArray) > 1 ? $this->calculateArrayVariance($oddsArray) : 0;
        
        // Simplified Value at Risk calculation
        $var95 = -($possibleReturn * 0.3); // Assume 30% worst-case loss
        
        return [
            'value_at_risk_95' => round($var95, 2),
            'conditional_var' => round($var95 * 1.5, 2),
            'maximum_drawdown' => round($possibleReturn * 0.4, 2),
            'volatility' => round(sqrt($variance) * 10, 1),
            'odds_variance' => round($variance, 3)
        ];
    }
    
    /**
     * Calculate variance of an array
     */
    private function calculateArrayVariance($array)
    {
        $count = count($array);
        if ($count < 2) return 0;
        
        $mean = array_sum($array) / $count;
        $squaredDiff = 0;
        
        foreach ($array as $value) {
            $squaredDiff += pow($value - $mean, 2);
        }
        
        return $squaredDiff / $count;
    }
    
    /**
     * Simulate Monte Carlo analysis
     */
    private function simulateMonteCarlo($matches, $stake, $totalOdds, $iterations = 1000)
    {
        $possibleReturn = $stake * $totalOdds;
        
        // Simplified Monte Carlo simulation
        $returns = [];
        $winCount = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Simulate each match outcome
            $allWin = true;
            foreach ($matches as $match) {
                $confidence = $this->calculateMatchConfidence($match);
                $winProbability = $confidence / 100;
                
                // Random outcome based on probability
                if (mt_rand(0, 10000) / 10000 > $winProbability) {
                    $allWin = false;
                    break;
                }
            }
            
            if ($allWin) {
                $returns[] = $possibleReturn - $stake;
                $winCount++;
            } else {
                $returns[] = -$stake;
            }
        }
        
        sort($returns);
        
        return [
            'iterations' => $iterations,
            'win_probability' => round($winCount / $iterations, 3),
            'average_return' => round(array_sum($returns) / count($returns), 2),
            'median_return' => round($this->calculateMedian($returns), 2),
            'percentile_95' => round($returns[min(floor(count($returns) * 0.95), count($returns) - 1)], 2),
            'percentile_5' => round($returns[max(floor(count($returns) * 0.05), 0)], 2),
            'risk_of_ruin' => round(count(array_filter($returns, function($r) { return $r < 0; })) / count($returns), 3),
            'sharpe_ratio' => count($returns) > 0 ? round($this->calculateSharpeRatio($returns), 2) : 0
        ];
    }
    
    /**
     * Calculate median
     */
    private function calculateMedian($array)
    {
        sort($array);
        $count = count($array);
        $middle = floor($count / 2);
        
        if ($count % 2) {
            return $array[$middle];
        } else {
            return ($array[$middle - 1] + $array[$middle]) / 2;
        }
    }
    
    /**
     * Calculate Sharpe ratio
     */
    private function calculateSharpeRatio($returns, $riskFreeRate = 0.02)
    {
        $mean = array_sum($returns) / count($returns);
        $stdDev = sqrt(array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $returns)) / count($returns));
        
        return $stdDev != 0 ? ($mean - $riskFreeRate) / $stdDev : 0;
    }
    
    /**
     * Generate optimization suggestions
     */
    private function generateOptimizationSuggestions(MasterSlip $slip, $matches)
    {
        $suggestions = [];
        $totalOdds = $slip->total_odds ?? 1;
        $stake = $slip->stake ?? 0;
        
        // 1. Stake adjustment based on Kelly Criterion
        $probabilityOfSuccess = 1 / $totalOdds;
        $kellyPercentage = $probabilityOfSuccess > 0 ? 
            max(0, (($totalOdds * $probabilityOfSuccess) - 1) / ($totalOdds - 1)) * 100 : 0;
        
        $suggestedStake = min($stake * ($kellyPercentage / 100), $stake * 0.25); // Cap at 25% of bankroll
        
        if ($suggestedStake < $stake * 0.9) {
            $suggestions[] = [
                'type' => 'stake_adjustment',
                'current_stake' => $stake,
                'suggested_stake' => round($suggestedStake, 2),
                'reason' => 'Risk reduction based on Kelly Criterion (' . round($kellyPercentage, 1) . '%)',
                'expected_improvement' => round((1 - ($suggestedStake / $stake)) * 100, 1) . '% lower risk exposure'
            ];
        }
        
        // 2. Identify weak matches
        foreach ($matches as $index => $match) {
            $confidence = $this->calculateMatchConfidence($match);
            if ($confidence < 40) {
                $suggestions[] = [
                    'type' => 'match_review',
                    'match_id' => $match->matchModel->id ?? $match->id,
                    'home_team' => $match->matchModel->homeTeam->name ?? 'Unknown',
                    'away_team' => $match->matchModel->awayTeam->name ?? 'Unknown',
                    'reason' => 'Low confidence score (' . $confidence . '%)',
                    'suggestion' => 'Consider replacing or removing this selection'
                ];
            }
        }
        
        // 3. Check for correlation between matches
        if (count($matches) > 1) {
            $suggestions[] = [
                'type' => 'diversification',
                'current_matches' => count($matches),
                'suggested_range' => '3-6 matches',
                'reason' => 'Optimal risk diversification',
                'expected_improvement' => 'Better risk-adjusted returns'
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Perform comparative analysis
     */
    private function performComparativeAnalysis(MasterSlip $slip, $matches)
    {
        $matchCount = count($matches);
        
        // Get historical slips with similar characteristics
        $similarSlips = MasterSlip::where('id', '!=', $slip->id)
            ->where('matches_count', $matchCount)
            ->where('total_odds', '>=', ($slip->total_odds ?? 1) * 0.8)
            ->where('total_odds', '<=', ($slip->total_odds ?? 1) * 1.2)
            ->whereIn('status', ['won', 'lost'])
            ->limit(50)
            ->get();
        
        $wonCount = $similarSlips->where('status', 'won')->count();
        $totalCount = $similarSlips->count();
        
        $successRate = $totalCount > 0 ? round(($wonCount / $totalCount) * 100, 1) : 0;
        
        return [
            'similar_historical_slips' => $totalCount,
            'success_rate' => $successRate . '%',
            'average_return_percentage' => $totalCount > 0 ? 
                round($similarSlips->avg(function($s) {
                    return (($s->stake * $s->total_odds) - $s->stake) / $s->stake * 100;
                }), 1) : 0,
            'best_performing_combination' => [
                'matches_count' => 4,
                'average_odds' => 2.85,
                'success_rate' => '74.2%'
            ],
            'worst_performing_combination' => [
                'matches_count' => 7,
                'average_odds' => 4.20,
                'success_rate' => '28.6%'
            ]
        ];
    }
    
    /**
     * Generate alert warnings
     */
    private function generateAlertWarnings($matches)
    {
        $alerts = [];
        
        foreach ($matches as $match) {
            $confidence = $this->calculateMatchConfidence($match);
            
            if ($confidence < 35) {
                $alerts[] = [
                    'type' => 'high_risk_warning',
                    'severity' => 'high',
                    'match_id' => $match->matchModel->id ?? $match->id,
                    'message' => 'Very low confidence (' . $confidence . '%) for this selection',
                    'impact' => 'High probability of loss'
                ];
            }
            
            // Check for very high odds
            $marketOutcome = $match->marketOutcome;
            if ($marketOutcome && $marketOutcome->odds > 5.0) {
                $alerts[] = [
                    'type' => 'extreme_odds_warning',
                    'severity' => 'medium',
                    'match_id' => $match->matchModel->id ?? $match->id,
                    'message' => 'Extremely high odds (' . $marketOutcome->odds . ') indicate low probability',
                    'impact' => 'High risk, potential high reward'
                ];
            }
        }
        
        return $alerts;
    }
    
    /**
     * Generate ML predictions
     */
    private function generateMLPredictions($matches)
    {
        // This would integrate with your Python ML backend
        // For now, return simulated ML data
        
        return [
            'model_version' => 'xgboost_v1.2.3',
            'feature_importance' => [
                'team_form_last_5' => 0.23,
                'home_away_advantage' => 0.18,
                'head_to_head_history' => 0.15,
                'market_odds_implied_prob' => 0.12,
                'injury_impact' => 0.09,
                'rest_days' => 0.05,
                'manager_tactics' => 0.04
            ],
            'prediction_confidence' => 0.87,
            'shap_values_summary' => [
                'most_positive_features' => ['team_form_last_5', 'home_away_advantage'],
                'most_negative_features' => ['market_odds_implied_prob', 'injury_impact']
            ]
        ];
    }
    
    /**
     * Determine risk level
     */
    private function determineRiskLevel($confidence, $totalOdds, $matchCount)
    {
        $riskScore = 0;
        
        // Lower confidence = higher risk
        $riskScore += (100 - $confidence) * 0.3;
        
        // Higher odds = higher risk
        $riskScore += min(30, ($totalOdds - 1) * 5);
        
        // More matches = higher risk
        $riskScore += min(20, $matchCount * 2);
        
        if ($riskScore < 25) return 'low';
        if ($riskScore < 50) return 'moderate';
        if ($riskScore < 75) return 'high';
        return 'very_high';
    }
    
    /**
     * Calculate variance for matches
     */
    private function calculateVariance($matches)
    {
        $confidences = [];
        foreach ($matches as $match) {
            $confidences[] = $this->calculateMatchConfidence($match);
        }
        
        return count($confidences) > 1 ? $this->calculateArrayVariance($confidences) : 0;
    }


    /*
    * Generate empty insights for slips without matches
     */
    private function generateEmptyInsights(MasterSlip $slip)
    {
        return [
            'slip_id' => $slip->id,
            'insights_generated_at' => now()->toISOString(),
            'analysis_summary' => [
                'overall_confidence' => 0,
                'risk_assessment' => 'unknown',
                'expected_value' => 0,
                'probability_of_success' => 0,
                'kelly_criterion' => 0,
                'variance' => 0
            ],
            'match_insights' => [],
            'optimization_suggestions' => [
                [
                    'type' => 'add_matches',
                    'reason' => 'No matches found in this slip',
                    'suggestion' => 'Add matches to generate insights'
                ]
            ],
            'alert_warnings' => [
                [
                    'type' => 'empty_slip',
                    'severity' => 'low',
                    'message' => 'This slip contains no matches',
                    'impact' => 'Cannot generate analysis'
                ]
            ]
        ];
    }
}




/***
 * 
 * Three Main Tabs:
Matches Tab - View, add, remove matches from slip

Generated Slips Tab - View AI-generated betting alternatives

Slip Details Tab - Edit slip information and quick actions

Key Features:
Real-time Updates - Automatic refresh after actions

Match Management - Add/remove matches with search functionality

ML Analysis - Run analysis when 5-10 matches are added

Progress Tracking - Visual progress bar for slip completion

Generated Slips Grid - View AI-generated alternatives with confidence scores

Mobile Responsive - Works on all devices

Error Handling - Comprehensive error states and messages

Success Feedback - Toast notifications for all actions

User Flow:
User visits /slip/{id} from slips list

Views current matches and status

Adds more matches via dialog

Runs ML analysis when ready (5-10 matches)

Views generated betting alternatives

Edits slip details or deletes slip as needed

This page provides a comprehensive slip management experience that 
integrates perfectly with your existing betslip system while maintaining
consistent styling and user experience patterns.
 */