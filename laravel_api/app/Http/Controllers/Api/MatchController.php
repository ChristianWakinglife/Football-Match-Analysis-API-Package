<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatchRequest;
use App\Http\Requests\UpdateMatchRequest;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\Team_Form;
use App\Models\Market;
use App\Models\MatchMarket;
use App\Models\MasterSlip;
use App\Models\AlternativeSlip;
use App\Models\MatchMarketOutcome;
use App\Models\Head_To_Head;
use App\Services\TeamService;
use App\Services\MatchIngestionService; // Add this import
use App\Jobs\ProcessMatchForML;
use App\Jobs\StoreMatchDataJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

class MatchController extends Controller
{
    protected MatchIngestionService $matchIngestionService;

    public function __construct(MatchIngestionService $matchIngestionService)
    {
        $this->matchIngestionService = $matchIngestionService;
    }

    /**
     * Display a listing of matches.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = MatchModel::query()
                ->select([
                    'id',
                    'home_team',           // ← string name (denormalized)
                    'away_team',           // ← string name (denormalized)
                    'league',
                    'match_date',
                    'status',
                    'home_score',
                    'away_score',
                    'analysis_status',
                    'prediction_ready',
                    'created_at',
                    'updated_at'
                ])
                ->withCount('markets as markets_count'); // ← shows number of markets

            // Apply filters
            if ($request->has('league')) {
                $query->where('league', $request->league);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('date_from')) {
                $query->whereDate('match_date', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->whereDate('match_date', '<=', $request->date_to);
            }

            if ($request->has('prediction_ready')) {
                $query->where('prediction_ready', $request->boolean('prediction_ready'));
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $matches = $query->latest('match_date')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $matches->items(),
                'meta' => [
                    'current_page' => $matches->currentPage(),
                    'last_page' => $matches->lastPage(),
                    'per_page' => $matches->perPage(),
                    'total' => $matches->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve matches', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve matches',
            ], 500);
        }
    }

    /**
     * Store a newly created match with all associated data.
     */
    public function storeSinglematch(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            // 1. Resolve or Create Teams to get IDs
            $homeTeam = Team::updateOrCreate(
                ['name' => $request->home_team],
                ['league' => $request->league]
            );

            $awayTeam = Team::updateOrCreate(
                ['name' => $request->away_team],
                ['league' => $request->league]
            );

            // 2. Create Core Match Record using resolved IDs
            $match = MatchModel::create([
                'home_team' => $request->home_team,
                'away_team' => $request->away_team,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'league' => $request->league,
                'match_date' => $request->match_date . ' ' . ($request->match_time ?? '00:00:00'),
                'status' => $request->status ?? 'scheduled',
                'venue' => $request->venue,
                'referee' => $request->referee,
                'weather_conditions' => $request->weather,
                'home_score' => $request->home_score,
                'away_score' => $request->away_score,
                'importance' => $request->notes,
                'prediction_ready' => false,
                'analysis_status' => 'pending'
            ]);

            // 3. Process Team Form (Using resolved IDs for team_id)
            $formsToProcess = [
                'home_form' => ['id' => $homeTeam->id, 'venue' => 'home'],
                'away_form' => ['id' => $awayTeam->id, 'venue' => 'away']
            ];

            foreach ($formsToProcess as $payloadKey => $meta) {
                if ($request->has($payloadKey)) {
                    $formData = $request->input($payloadKey);
                    $match->teamForms()->create([
                        'team_id' => $meta['id'],
                        'venue' => $meta['venue'],
                        'form_rating' => $formData['form_rating'] ?? 50.00,
                        'raw_form' => json_encode($formData['raw_form'] ?? []),
                        'form_momentum' => $formData['form_momentum'] ?? 0.00,
                        'matches_played' => $formData['matches_played'] ?? 0,
                        'wins' => $formData['wins'] ?? 0,
                        'draws' => $formData['draws'] ?? 0,
                        'losses' => $formData['losses'] ?? 0,
                        'goals_scored' => $formData['goals_scored'] ?? 0,
                        'goals_conceded' => $formData['goals_conceded'] ?? 0,
                        'avg_goals_scored' => $formData['avg_goals_scored'] ?? 0.00,
                        'avg_goals_conceded' => $formData['avg_goals_conceded'] ?? 0.00,
                        'clean_sheets' => $formData['clean_sheets'] ?? 0,
                        'failed_to_score' => $formData['failed_to_score'] ?? 0,
                        'form_string' => $formData['form_string'] ?? null,
                        'calculated_at' => now(),
                    ]);
                }
            }

            // 4. Process Head-to-Head Statistics
            if ($request->has('head_to_head_stats.last_meetings')) {
                foreach ($request->input('head_to_head_stats.last_meetings') as $meeting) {
                    $scores = explode('-', $meeting['score']);
                    $match->historicalResults()->create([
                        'home_team' => $meeting['home_team'],
                        'away_team' => $meeting['away_team'],
                        'home_score' => trim($scores[0] ?? 0),
                        'away_score' => trim($scores[1] ?? 0),
                        'match_date' => $meeting['date'],
                    ]);
                }
            }

            // 5. Process Markets and Outcomes
            if ($request->has('markets')) {
                foreach ($request->markets as $marketData) {
                    $market = Market::where('code', $marketData['market_type'])->first();
                    if ($market) {
                        $match->matchMarkets()->create([
                            'market_id' => $market->id,
                            'market_data' => [
                                'outcomes' => $marketData['outcomes'],
                                'is_active' => true
                            ]
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Match stored with resolved Team IDs.',
                'id' => $match->id
            ], 201);
        });
    }
    /**
     * Store a newly created match (synchronous)
     */
    public function storeMatchData(StoreMatchRequest $request): JsonResponse
    {
        try {
            $result = $this->matchIngestionService->storeMatchData($request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Match created successfully',
                'data' => $result['match'],
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Failed to create match', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create match',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Store a newly created match asynchronously (queued)
     */
    public function storeMatchDataAsync(StoreMatchRequest $request): JsonResponse
    {
        try {
            $this->matchIngestionService->queueMatchData($request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Match data queued for processing',
                'data' => [
                    'status' => 'queued',
                    'queue' => 'match-ingestion',
                ]
            ], 202); // 202 Accepted
            
        } catch (\Exception $e) {
            Log::error('Failed to queue match data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue match data',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Bulk store matches (queued)
     */
    public function bulkStoreMatches(Request $request): JsonResponse
    {
        $request->validate([
            'matches' => 'required|array|min:1',
            'matches.*' => 'array',
            'matches.*.home_team' => 'required|string',
            'matches.*.away_team' => 'required|string',
            'matches.*.league' => 'required|string',
            'matches.*.match_date' => 'required|date',
        ]);

        $matches = $request->input('matches');
        $queuedCount = 0;
        $errors = [];

        foreach ($matches as $index => $matchData) {
            try {
                $dto = $this->matchIngestionService->validateAndCreateDTO($matchData);
                
                StoreMatchDataJob::dispatch($dto)
                    ->onQueue('bulk-match-ingestion');
                
                $queuedCount++;
                
            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'data' => $matchData,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk match ingestion queued',
            'data' => [
                'total' => count($matches),
                'queued' => $queuedCount,
                'errors' => $errors,
                'error_count' => count($errors),
            ]
        ], 202);
    }


        /**
     * Remove the specified match.
     */
public function destroy(string $id): JsonResponse
{
    DB::beginTransaction();

    try {
        $match = MatchModel::with(['markets', 'teamForms'])->findOrFail($id);

        // Check if match can be deleted (no dependent records)
        if ($match->predictions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete match with existing predictions',
            ], 422);
        }

        // Delete related data first
        if ($match->markets()->exists()) {
            $match->markets()->delete();
        }

        if ($match->teamForms()->exists()) {
            $match->teamForms()->delete();
        }

        // Delete the match
        $match->delete();

        DB::commit();

        Log::info('Match deleted successfully', [
            'match_id' => $id,
            'deleted_relations' => [
                'markets' => $match->markets->count(),
                'teamForms' => $match->teamForms->count(),
            ]
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Match and all associated data deleted successfully',
        ]);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        DB::rollBack();
        
        Log::warning('Attempted to delete non-existent match', ['match_id' => $id]);
        
        return response()->json([
            'success' => false,
            'message' => 'Match not found',
        ], 404);
    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();

        Log::error('Database error while deleting match', [
            'match_id' => $id,
            'error' => $e->getMessage(),
            'sql' => $e->getSql(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Database error occurred while deleting match',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    } catch (\Exception $e) {
        DB::rollBack();

        Log::error('Failed to delete match', [
            'match_id' => $id,
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete match',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    /**
     * Display the specified match.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $match = MatchModel::query()
                ->select([
                    'id',
                    'home_team',
                    'away_team',
                    'league',
                    'match_date',
                    'status',
                    'home_score',
                    'away_score',
                    'analysis_status',
                    'prediction_ready',
                    'created_at',
                    'updated_at'
                ])
                ->with([
                    'headToHead',
                    'teamForms',
                    'markets' => function ($query) {
                        $query->orderBy('sort_order', 'asc')
                            ->wherePivot('is_active', true);
                    },
                ])
                ->withCount('markets as markets_count')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $match,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Match not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve match', [
                'match_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve match',
            ], 500);
        }
    }


    public function getMatchesAllForBetslip(): JsonResponse
{
    try {
        // 1. Fetch the base match records first to ensure they exist
        $matches = MatchModel::query()
            ->select(['id', 'home_team', 'away_team', 'league', 'match_date', 'status'])
            ->where('status', 'scheduled')
            ->orderBy('match_date', 'asc')
            ->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No scheduled matches found for the betslip.',
                'data' => []
            ], 200);
        }

        // 2. Attempt to load Markets and specifically include the 'odds' column
        try {
            $matches->load(['matchMarkets' => function($query) {
                // IMPORTANT: Added 'odds' and 'is_active' to the select list
                $query->select(['id', 'match_id', 'market_id', 'market_data', 'odds', 'is_active'])
                      ->where('is_active', true); 
                
                $query->with(['market' => function($mQuery) {
                    $mQuery->select(['id', 'name', 'code']);
                }]);
            }]);

            // 3. Specific validation: Check if we actually retrieved markets
            $totalMarketsLoaded = $matches->sum(fn($m) => $m->matchMarkets->count());

            if ($totalMarketsLoaded === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to retrieve markets: Matches exist but no active markets or odds were found.',
                    'error_code' => 'EMPTY_MARKET_DATA'
                ], 422);
            }

        } catch (\Exception $relationException) {
            // This triggers if there is a SQL error specifically in the market join/query
            return response()->json([
                'success' => false,
                'message' => 'A database error occurred while specifically attempting to fetch market odds.',
                'debug' => $relationException->getMessage()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'count' => $matches->count(),
            'data' => $matches
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred while retrieving betslip data.',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Manually trigger ML processing for a match (user-controlled)
     */
    public function generatePredictionsSingle(string $id): JsonResponse
    {
        try {
            $match = MatchModel::findOrFail($id);

            // Optional: prevent duplicate running
            if (in_array($match->analysis_status, ['processing', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis already running or completed for this match',
                    'status' => $match->analysis_status,
                ], 409);
            }

            // Update status to show it's processing
            $match->analysis_status = 'processing';
            $match->save();

            // // Dispatch the heavy job in background
            // ProcessMatchForML::dispatch($match->id, 'full')->onQueue('ml-processing');

            Log::info('User triggered ML processing', ['match_id' => $id, 'user_id' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'Analysis started! You will be notified when predictions and slips are ready.',
                'match_id' => $match->id,
                'status' => 'processing',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Match not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to trigger ML processing', [
                'match_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start analysis',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // app/Http/Controllers/Api/MatchController.php (add method)
    public function generatePredictions(string $id): JsonResponse
    {
        $match = MatchModel::with(['markets', 'teamForms', 'headToHead'])->find($id);

        if (!$match) {
            return response()->json(['success' => false, 'message' => 'Match not found'], 404);
        }

        $masterSlip = MasterSlip::create([
            'match_id' => $match->id,
            'stake' => 10.00,
            'status' => 'completed',
        ]);

        $markets = $match->markets;

        for ($i = 0; $i < 50; $i++) {
            $numSelections = rand(2, min(6, $markets->count()));
            $selectedMarkets = $markets->random($numSelections);

            $totalOdds = 1.0;
            $selections = [];

            foreach ($selectedMarkets as $market) {
                $odds = round(rand(120, 450) / 100, 2);
                $totalOdds *= $odds;

                $selections[] = [
                    'market_id' => $market->id,
                    'odds' => $odds,
                ];
            }

            $potentialReturn = round($totalOdds * $masterSlip->stake, 2);

            AlternativeSlip::create([
                'master_slip_id' => $masterSlip->id,
                'total_odds' => round($totalOdds, 2),
                'potential_return' => $potentialReturn,
                'selections' => $selections,
            ]);
        }

        $match->analysis_status = 'completed';
        $match->save();

        return response()->json([
            'success' => true,
            'match_id' => $match->id,
            'master_slip_id' => $masterSlip->id,
            'slips_created' => 50,
            'status' => 'completed',
        ]);
    }

    //Laravel layer can perform several "Orchestrator" tasks:
    //SLA Monitoring: If $processTime exceeds a threshold (e.g., 2.0 seconds), Laravel can
    //trigger an alert that the Python engine is struggling or needs more CPU resources

    //Database Logging: You can save this processing time in your Laravel match_analysis_logs table
    //This helps you track which types of slips (e.g., slips with 20 matches vs 5 matches) take the most computational effort.

    public function generateEngineSlips(Request $request)
    {
        $masterSlipData = $request->master_slip;
        // 1. Send the POST request to the Python Engine
        $response = Http::post('http://localhost:5000/generate-slips', $masterSlipData);

        if ($response->successful()) {
            // 2. Retrieve the custom header we defined in Python
            $processTime = $response->header('X-Process-Time');

            // 3. Log it or store it for performance monitoring
            Log::info("Python Engine processed slip ID {$masterSlipData['master_slip_id']} in {$processTime} seconds.");

            // 4. Return the body (the generated slips)
            return $response->json();
        }

        throw new \Exception("Engine Error: " . $response->body());
    }

}