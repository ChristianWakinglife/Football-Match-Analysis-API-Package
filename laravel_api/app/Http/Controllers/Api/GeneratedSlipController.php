<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterSlip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeneratedSlipController extends Controller
{
    /**
     * Fetch generated slips for a master slip with normalized response
     */
    public function getGeneratedSlips($masterSlipId)
    {
        Log::info('📋 Fetching generated slips', ['master_slip_id' => $masterSlipId]);

        try {
            // Find the master slip with its generated slips and legs
            $masterSlip = MasterSlip::with([
                'generatedSlips' => function ($query) {
                    $query->orderBy('created_at', 'desc')
                        ->with([
                            'legs' => function ($q) {
                                $q->orderBy('id', 'asc');
                            }
                        ]);
                }
            ])->find($masterSlipId);

            if (!$masterSlip) {
                Log::warning('❌ Master slip not found', ['master_slip_id' => $masterSlipId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Master slip not found',
                    'master_slip_id' => $masterSlipId,
                ], 404);
            }

            Log::info('✅ Master slip found', [
                'master_slip_id' => $masterSlipId,
                'generated_slips_count' => $masterSlip->generatedSlips->count(),
            ]);

            // Normalize the response for frontend
            $normalizedResponse = $this->normalizeSlipsResponse($masterSlip);

            return response()->json([
                'success' => true,
                'message' => 'Generated slips retrieved successfully',
                'data' => $normalizedResponse,
                'count' => count($normalizedResponse['generated_slips']),
            ]);

        } catch (\Exception $e) {
            Log::error('💥 Error fetching generated slips', [
                'master_slip_id' => $masterSlipId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch generated slips',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Normalize slips response for frontend consumption
     */
    protected function normalizeSlipsResponse(MasterSlip $masterSlip): array
    {
        $generatedSlips = [];

        foreach ($masterSlip->generatedSlips as $slip) {
            $legs = [];
            $totalOdds = 1.0;

            foreach ($slip->legs as $leg) {
                $legs[] = [
                    'match_id' => $leg->match_id,
                    'market' => $leg->market,
                    'selection' => $leg->selection,
                    'odds' => (float) $leg->odds,
                    'is_fallback' => (bool) $leg->is_fallback,
                ];
                $totalOdds *= (float) $leg->odds;
            }

            $generatedSlips[] = [
                'id' => $slip->id,
                'slip_id' => $slip->slip_id,
                'stake' => (float) $slip->stake,
                'total_odds' => (float) $slip->total_odds,
                'calculated_odds' => round($totalOdds, 2), // For verification
                'possible_return' => (float) $slip->possible_return,
                'risk_level' => $slip->risk_level,
                'confidence_score' => (float) $slip->confidence_score,
                'variation_type' => $slip->variation_type,
                'edge_score' => (float) $slip->edge_score,
                'created_at' => $slip->created_at->toISOString(),
                'legs' => $legs,
                'legs_count' => count($legs),
                'metrics' => [
                    'roi_percentage' => $slip->stake > 0 ?
                        round((($slip->possible_return - $slip->stake) / $slip->stake) * 100, 2) : 0,
                    'expected_value' => round($slip->possible_return * ($slip->confidence_score / 100), 2),
                ]
            ];
        }

        // Sort by confidence score (highest first) then by possible return
        usort($generatedSlips, function ($a, $b) {
            if ($b['confidence_score'] !== $a['confidence_score']) {
                return $b['confidence_score'] <=> $a['confidence_score'];
            }
            return $b['possible_return'] <=> $a['possible_return'];
        });

        return [
            'master_slip' => [
                'id' => $masterSlip->id,
                'custom_id' => $masterSlip->custom_id,
                'stake' => (float) $masterSlip->stake,
                'currency' => $masterSlip->currency,
                'status' => $masterSlip->status,
                'total_matches' => $masterSlip->total_matches,
                'risk_profile' => $masterSlip->risk_profile,
                'created_at' => $masterSlip->created_at->toISOString(),
                'total_generated_slips' => $masterSlip->total_generated_slips,
                'failed_slips_count' => $masterSlip->failed_slips_count,
                'engine_version' => $masterSlip->engine_version,
            ],
            'generated_slips' => $generatedSlips,
            'summary' => [
                'total_slips' => count($generatedSlips),
                'total_investment' => array_sum(array_column($generatedSlips, 'stake')),
                'total_possible_return' => array_sum(array_column($generatedSlips, 'possible_return')),
                'average_confidence' => count($generatedSlips) > 0 ?
                    round(array_sum(array_column($generatedSlips, 'confidence_score')) / count($generatedSlips), 2) : 0,
                'risk_distribution' => $this->calculateRiskDistribution($generatedSlips),
                'top_slip' => !empty($generatedSlips) ? $generatedSlips[0] : null,
            ]
        ];
    }

    /**
     * Calculate risk distribution across slips
     */
    protected function calculateRiskDistribution(array $slips): array
    {
        $distribution = [
            'High' => 0,
            'Medium' => 0,
            'Low' => 0,
        ];

        foreach ($slips as $slip) {
            $riskLevel = $slip['risk_level'] ?? 'Medium';
            if (isset($distribution[$riskLevel])) {
                $distribution[$riskLevel]++;
            }
        }

        $total = array_sum($distribution);
        if ($total > 0) {
            foreach ($distribution as $key => $value) {
                $distribution[$key] = [
                    'count' => $value,
                    'percentage' => round(($value / $total) * 100, 1)
                ];
            }
        }

        return $distribution;
    }

    /**
     * Get single generated slip detail
     */
    public function getSlipDetail($generatedSlipId)
    {
        try {
            // Assuming you have a GeneratedSlip model
            $slip = \App\Models\GeneratedSlip::with('legs', 'masterSlip')->find($generatedSlipId);

            if (!$slip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Generated slip not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'slip' => $slip,
                    'master_slip' => $slip->masterSlip,
                    'legs' => $slip->legs,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching slip detail', [
                'generated_slip_id' => $generatedSlipId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch slip details',
            ], 500);
        }
    }
}

// Generated slips routes
// Route::get('/master-slips/{masterSlipId}/generated-slips', [GeneratedSlipController::class, 'getGeneratedSlips'])
//     ->where('masterSlipId', '[0-9]+')
//     ->name('generated-slips.get');

// Route::get('/generated-slips/{generatedSlipId}', [GeneratedSlipController::class, 'getSlipDetail'])
//     ->where('generatedSlipId', '[0-9]+')
//     ->name('generated-slips.detail');