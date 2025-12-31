<?php

// app/Http/Controllers/Api/MasterSlipController.php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAlternativeSlipsJob;
use App\Models\MasterSlip;
use App\Models\MasterSlipSelection;
use App\Models\MatchModel;
use App\Models\MatchMarket;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Models\Team_Form;
use App\Jobs\PrepareMLMatchDataJob;

use Illuminate\Http\JsonResponse;


class MasterSlipController extends Controller
{
    public function store(Request $request)

    {

        $request->validate([
            'stake' => 'numeric|min:1',
            'matches' => 'required|array|min:1',
            'matches.*.match_id' => 'required|exists:matches,id',
            'matches.*.markets' => 'required|array|min:1',
            'matches.*.markets.*.type' => 'required|string',
            'matches.*.markets.*.selection' => 'required|string',
            'matches.*.markets.*.odds' => 'required|numeric|min:1.01',
        ]);

        DB::beginTransaction();
        try {
            $masterSlip = MasterSlip::create([
                'stake' => $request->stake ?? 100,
                'raw_payload' => $request->all(),
                'status' => 'pending',
            ]);

            foreach ($request->matches as $matchItem) {
                MasterSlipSelection::create([
                    'master_slip_id' => $masterSlip->id,
                    'match_id' => $matchItem['match_id'],
                    'markets' => $matchItem['markets'],
                ]);
            }

            // Dispatch job
            GenerateAlternativeSlipsJob::dispatch($masterSlip->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master Slip submitted. Generating alternatives...',
                'master_slip_id' => $masterSlip->id,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Master Slip creation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit Master Slip',
            ], 500);
        }
    }


    public function addMatchToMasterSlip(Request $request, string $masterSlipId)
    {
        // Validate input parameters first
        $validatedRequest = $request->validate([
            'match_id' => 'required|integer|exists:matches,id',
            'match_market_id' => 'required|integer|exists:match_markets,id',
            'outcome_key' => 'required|string',
        ]);

        try {
            // Load the master slip with proper error handling
            $masterSlip = $this->loadMasterSlip($masterSlipId);

            // Check for duplicate match
            $this->validateNoDuplicateMatch($masterSlip, $validatedRequest['match_id']);

            // Load match with full context
            $match = $this->loadMatchWithContext($validatedRequest['match_id']);

            // Find and validate the specific match market
            $matchMarket = $this->findMatchMarket(
                $validatedRequest['match_market_id'],
                $match->id
            );

            // Find and validate the outcome
            $outcome = $this->findMarketOutcome(
                $matchMarket,
                $validatedRequest['outcome_key']
            );

            // Create the slip match with all snapshots
  


                // ATOMIC OPERATION: Create slip match + update totals
            $slipMatch = DB::transaction(function () use ($masterSlip, $match, $matchMarket, $outcome) {
                $createdSlipMatch = $this->createSlipMatch($masterSlip, $match, $matchMarket, $outcome);
                $this->updateSlipTotals($masterSlip);
                return $createdSlipMatch;
            });

            //log slipmatch
            Log::info('Match added to master slip', [
                'master_slip_id' => $slipMatch->id,
                'match_id' => $match->id,
            ]);


            return response()->json([
                'success' => true,
                'message' => 'Match added successfully',
                'data' => $masterSlip->load('SlipMatches'), // or whatever your relation name is
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Resource not found when adding match to slip', [
                'master_slip_id' => $masterSlipId,
                'request_data' => $validatedRequest,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The requested resource was not found',
            ], 404);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exceptions to let Laravel handle them properly
            throw $e;

        } catch (\Exception $e) {
            Log::error('Failed to add match to slip', [
                'master_slip_id' => $masterSlipId,
                'request_data' => $validatedRequest,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add match to slip. Please try again.',
            ], 500);

            
        }
    }

    /**
     * Load master slip with proper error handling
     */
    private function loadMasterSlip(string $masterSlipId): MasterSlip
    {
        $masterSlip = MasterSlip::find($masterSlipId);

        if (!$masterSlip) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Master slip with ID {$masterSlipId} not found"
            );
        }

        return $masterSlip;
    }

    /**
     * Validate that the match is not already in the slip
     */
    private function validateNoDuplicateMatch(MasterSlip $masterSlip, int $matchId): void
    {
        $exists = $masterSlip->lipMatches()
            ->where('match_id', $matchId)
            ->exists();

        if ($exists) {
            throw new \Illuminate\Validation\ValidationException(
                \Illuminate\Support\Facades\Validator::make([], [])
                    ->errors()
                    ->add('match_id', 'Match already added to slip')
            );
        }
    }

    /**
     * Load match with all necessary relationships
     */
    private function loadMatchWithContext(int $matchId): MatchModel
    {
        $match = MatchModel::with([
            'homeTeam',
            'awayTeam',
            'matchMarkets.market.marketOutcomes',
        ])->find($matchId);

        if (!$match) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Match with ID {$matchId} not found"
            );
        }

        return $match;
    }

    /**
     * Find and validate the match market
     */
    private function findMatchMarket(int $matchMarketId, int $matchId): MatchMarket
    {
        $matchMarket = MatchMarket::with('market.outcomes')
            ->where('id', $matchMarketId)
            ->where('match_id', $matchId)
            ->first();

        if (!$matchMarket) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Match market with ID {$matchMarketId} not found for this match"
            );
        }

        return $matchMarket;
    }

    /**
     * Find and validate the market outcome
     */
    private function findMarketOutcome(MatchMarket $matchMarket, string $outcomeKey): \Illuminate\Database\Eloquent\Model
    {
        $outcome = $matchMarket->market->outcomes
            ->firstWhere('outcome_key', $outcomeKey);

        if (!$outcome) {
            throw new \Illuminate\Validation\ValidationException(
                \Illuminate\Support\Facades\Validator::make([], [])
                    ->errors()
                    ->add('outcome_key', 'Invalid market outcome selected')
            );
        }

        return $outcome;
    }

    /**
     * Create slip match with all snapshot data
     */
    private function createSlipMatch(
        MasterSlip $masterSlip,
        MatchModel $match,
        MatchMarket $matchMarket,
        $outcome
    ): \Illuminate\Database\Eloquent\Model {
        return $masterSlip->slipMatches()->create([
            'match_id' => $match->id,
            'selection' => $outcome->label,
            'odds' => (float) $outcome->odds,

            'selected_market' => [
                'match_market_id' => $matchMarket->id,
                'market_id' => $matchMarket->market->id,
                'market_code' => $matchMarket->market->code,
                'market_name' => $matchMarket->market->name,
                'outcome_key' => $outcome->outcome_key,
                'outcome_label' => $outcome->label,
                'odds' => (float) $outcome->odds,
            ],

            'markets' => $match->matchMarkets->map(function ($mm) {
                return [
                    'match_market_id' => $mm->id,
                    'market_id' => $mm->market->id,
                    'market_code' => $mm->market->code,
                    'market_name' => $mm->market->name,
                    'outcomes' => $mm->market->outcomes->map(fn($o) => [
                                'key' => $o->outcome_key,
                                'label' => $o->label,
                                'odds' => (float) $o->odds,
                            ]),
                ];
            })->values(),

            'match_data' => [
                'id' => $match->id,
                'league' => $match->league,
                'kickoff' => $match->match_date,
                'home_team' => $match->homeTeam?->name,
                'away_team' => $match->awayTeam?->name,
                'sport' => $match->sport,
            ],
        ]);
    }

    /**
     * Update slip totals (odds and payout)
     */
    private function updateSlipTotals(MasterSlip $masterSlip): void
    {
        try {
            // For accumulator bets: multiply all individual odds
            $totalOdds = $masterSlip->slipMatches()
                ->pluck('odds')
                ->reduce(fn(float $carry, float $odds) => $carry * $odds, 1.0);

            // Fallback if no matches yet
            $totalOdds = $totalOdds ?: 1.0;

            $masterSlip->update([
                'total_odds' => round($totalOdds, 4),
                'estimated_payout' => round($totalOdds * ($masterSlip->stake ?? 0), 2),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to update slip totals', [
                'master_slip_id' => $masterSlip->id,
                'error' => $e->getMessage(),
            ]);
            // Do not fail the whole operation if totals fail
        }
    }

    // prepare match for ml
    public function prepareMatchDataForML( int $masterSlipId): JsonResponse
    {
        // Dispatch the job to prepare ML match data
        $match = MasterSlip::with([
            'matches' => function ($query) {
                $query->with([
                    'homeTeam' => function ($teamQuery) {
                        $teamQuery->with(['leaguePosition', 'statistics']);
                    },
                    'awayTeam' => function ($teamQuery) {
                        $teamQuery->with(['leaguePosition', 'statistics']);
                    },
                    'league',
                    'competition',
                    'season',
                    'venue',
                    'referee',
                    'weather',
                    'markets' => function ($marketQuery) {
                        $marketQuery->with([
                            'outcomes',
                            'marketType'
                        ]);
                    }
                ]);
            },
            'riskProfile'
        ])->find($masterSlipId);

        return response()->json([
            'success' => true,
            'masterslip' => $match,
        ]);
    }

    //get team form using team id

public function teamFormData(int $teamId): JsonResponse
{
    $teamform = Team_Form::where('team_id', $teamId)
        ->select([
            '*',
        ])
        ->selectRaw('
            team_id_old_od,
            team_old_id,
            match_id_old_od,
            venue_old_old
        ')
        ->first();

    return response()->json([
        'success' => true,
        'team' => $teamform,
    ]);
}


    public function insertTemporaryForm(int $id)
    {
      $result =  DB::table('team_forms')->updateOrInsert(
            ['team_id' => $id],
            [
                'league_position' => random_int(3, 7),
                'points' => random_int(18, 50),
                // 'country' => 'England',
                // 'league' => 'Premier League',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $result;
    }



    public function deleteGeneratedSlips($masterSlipId)
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
                'status' => 'draft',
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
/*
// payload sample

// {
//   "match_id": 1284,
//   "match_market_id": 5541,
//   "outcome_key": "home_win"
// }
*/