<?php
// app/Jobs/GenerateAlternativeSlipsJob.php

namespace App\Jobs;

use App\Models\MasterSlip;
use App\Models\MasterSlipMatch;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\Venue;
use App\Models\League;
use App\Models\Referee;
use App\Models\Market;
use App\Services\TeamFormService;
use App\Services\HeadToHeadService;
use App\Services\MarketDataService;
use App\Services\MatchMarketOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;


//use processpythonrequest job
use App\Jobs\ProcessPythonRequest;
use App\Jobs\StoreGeneratedSlips;

class GenerateAlternativeSlipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;
    public $tries = 3;
    public $backoff = [30, 60, 120];

    protected $masterSlipId;
    protected $predictionType;
    protected $riskProfile;
    protected $jobId;
    protected $logContext = [];

    public function __construct($masterSlipId, $predictionType = 'monte_carlo', $riskProfile = 'medium', $jobId = null)
    {
        $this->masterSlipId = $masterSlipId;
        $this->predictionType = $predictionType;
        $this->riskProfile = $riskProfile;
        $this->jobId = $jobId ?? uniqid('altslip_', true);

        // Initialize logging context
        $this->logContext = [
            'job_id' => $this->jobId,
            'master_slip_id' => $this->masterSlipId,
            'prediction_type' => $this->predictionType,
            'risk_profile' => $this->riskProfile,
            'queue' => 'slip_generation',
            'process' => 'GenerateAlternativeSlipsJob'
        ];

        $this->onQueue('slip_generation');

        Log::info('🔄 GenerateAlternativeSlipsJob instantiated', array_merge($this->logContext, [
            'action' => 'job_instantiation',
            'timestamp' => now()->toISOString()
        ]));
    }



    public function handle(
        TeamFormService $teamFormService,
        HeadToHeadService $headToHeadService,
        MarketDataService $marketDataService
    ) {
        Log::info('🚀 Starting alternative slip generation process', array_merge($this->logContext, [
            'action' => 'job_started',
            'attempt' => $this->attempts(),
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'timestamp' => now()->toISOString()
        ]));

        try {
            Log::debug('📥 Loading master slip data from database', array_merge($this->logContext, [
                'action' => 'loading_master_slip',
                'step' => 'load_master_slip_data'
            ]));

            $masterSlip = $this->loadMasterSlipData();

            // Get matches through pivot table
            $matchesCount = $masterSlip->matches()->count();
            $pivotRecords = $masterSlip->slipMatches()->with([
                'match' => function ($query) {
                    $query->with([
                        'homeTeam',
                        'awayTeam',
                        'matchMarkets.market.marketOutcomes'
                    ]);
                }
            ])->get();

            Log::info('✅ Master slip data loaded successfully', array_merge($this->logContext, [
                'action' => 'master_slip_loaded',
                'master_slip_status' => $masterSlip->status,
                'matches_count' => $matchesCount,
                'pivot_records_count' => $pivotRecords->count(),
                'stake' => $masterSlip->stake,
                'currency' => $masterSlip->currency,
                'pivot_sample' => $pivotRecords->first() ? [
                    'match_id' => $pivotRecords->first()->match_id,
                    'market' => $pivotRecords->first()->market,
                    'selection' => $pivotRecords->first()->selection,
                    'odds' => $pivotRecords->first()->odds
                ] : 'No pivot records'
            ]));

            if ($matchesCount < 2) {
                Log::warning('⚠️ Master slip has insufficient matches', array_merge($this->logContext, [
                    'action' => 'validation_failed',
                    'matches_count' => $matchesCount,
                    'minimum_required' => 2,
                    'slip_id' => $masterSlip->id,
                    'slip_status' => $masterSlip->status
                ]));

                throw new \Exception('Master slip must contain at least 2 matches for alternative slip generation');
            }

            Log::debug('📦 Preparing Python engine payload', array_merge($this->logContext, [
                'action' => 'preparing_payload',
                'step' => 'prepare_python_payload',
                'services_used' => ['TeamFormService', 'HeadToHeadService', 'MarketDataService']
            ]));

            $payload = $this->preparePythonPayload($masterSlip, $pivotRecords, $teamFormService, $headToHeadService, $marketDataService);

            Log::info('📤 Payload prepared successfully', array_merge($this->logContext, [
                'action' => 'payload_prepared',
                'payload_size' => strlen(json_encode($payload)) / 1024 . ' KB',
                'match_count' => count($payload['master_slip']['matches']),
                'payload_summary' => [
                    'master_slip_id' => $payload['master_slip']['master_slip_id'],
                    'total_matches' => $payload['master_slip']['total_matches'],
                    'risk_profile' => $payload['master_slip']['risk_profile'],
                    'stake' => $payload['master_slip']['stake'],
                    'currency' => $payload['master_slip']['currency']
                ]
            ]));

            Log::debug('🚀 Dispatching to Python engine', array_merge($this->logContext, [
                'action' => 'dispatching_to_python',
                'step' => 'dispatch_to_python_engine',
                'queue' => 'python_requests',
                'masterslipId' => $this->masterSlipId
            ]));

            // Update master slip status
            $masterSlip->update([
                'status' => 'processing',
                'processing_started_at' => now(),
                'job_id' => $this->jobId,
                'last_updated_at' => now(),
                'processing_metadata' => [
                    'prediction_type' => $this->predictionType,
                    'risk_profile' => $this->riskProfile,
                    'job_started_at' => now()->toISOString(),
                    'payload_prepared_at' => now()->toISOString(),
                    'matches_processed' => $matchesCount
                ]
            ]);

            Log::info('✅ Master slip payload prepared and dispatched to Python engine', array_merge($this->logContext, [
                'action' => 'job_completed_successfully',
                'execution_time' => microtime(true) - LARAVEL_START,
                'final_status' => 'processing',
                'next_step' => 'python_engine_processing',
                'timestamp' => now()->toISOString()
            ]));
            $pythonResponse = $this->dispatchToPythonEngine($this->masterSlipId, $payload);

            Log::info('🔄 Updating master slip status', array_merge($this->logContext, [
                'action' => 'updating_master_slip',
                'new_status' => 'processing',
                'previous_status' => $masterSlip->status
            ]));


            //update masterslip status
            if ($pythonResponse) {
                $masterSlip->update([
                    'status' => 'processing',
                    'processing_updated_at' => now(),
                ]);

            } else {
                Log::warning('⚠️ No response from Python engine', array_merge($this->logContext, [
                    'action' => 'no_python_response',
                    'master_slip_id' => $this->masterSlipId
                ]));

            }



        } catch (\Exception $e) {
            Log::error('❌ Failed to prepare master slip payload', array_merge($this->logContext, [
                'action' => 'payload_preparation_failed',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
                'execution_time' => microtime(true) - LARAVEL_START,
                'trace' => $this->getSimplifiedTrace($e)
            ]));

            $this->handleFailure($e);
        }
    }


    protected function loadMasterSlipData()
    {
        Log::debug('🔍 Querying master slip from database', array_merge($this->logContext, [
            'action' => 'database_query',
            'model' => 'MasterSlip',
            'master_slip_id' => $this->masterSlipId,
        ]));

        // First, get the master slip without relations to debug
        $masterSlip = MasterSlip::find($this->masterSlipId);

        if (!$masterSlip) {
            throw new \Exception("Master slip not found");
        }

        // Debug what relationships exist
        Log::debug('🔍 Checking available relationships', array_merge($this->logContext, [
            'action' => 'debug_relationships',
            'available_methods' => [
                'matches' => method_exists($masterSlip, 'matches'),
                'masterSlipMatches' => method_exists($masterSlip, 'masterSlipMatches'),
                'slipMatches' => method_exists($masterSlip, 'slipMatches'),
                'master_slip_matches' => method_exists($masterSlip, 'master_slip_matches'),
            ]
        ]));

        // Now load with correct relationship
        $masterSlip = MasterSlip::with([
            'matches' => function ($query) {
                $query->with([
                    'homeTeam',
                    'awayTeam',
                    'matchMarkets.market.marketOutcomes',
                ]);
            },
            'slipMatches' => function ($query) { // Try this based on your previous code
                $query->with([
                    'match' => function ($q) {
                    $q->with([
                        'homeTeam',
                        'awayTeam',
                        'matchMarkets.market.marketOutcomes',
                    ]);
                }
                ]);
            }
        ])->findOrFail($this->masterSlipId);

        Log::debug('✅ Master slip database query successful', array_merge($this->logContext, [
            'action' => 'database_query_complete',
            'master_slip_found' => !is_null($masterSlip),
            'matches_count' => $masterSlip->matches()->count(),
            'master_slip_matches_count' => $masterSlip->slipMatches()->count(),
            'loaded_relations' => array_keys($masterSlip->getRelations())
        ]));

        Log::info('📊 Master slip validation passed', array_merge($this->logContext, [
            'action' => 'validation_passed',
            'matches_count' => $masterSlip->matches()->count(),
            'first_match_id' => $masterSlip->matches->first()->id ?? null,
            'last_match_id' => $masterSlip->matches->last()->id ?? null
        ]));

        return $masterSlip;
    }

    protected function preparePythonPayload(
        $masterSlip,
        $pivotRecords,
        TeamFormService $teamFormService,
        HeadToHeadService $headToHeadService,
        MarketDataService $marketDataService
    ): array {
        Log::debug('🧩 Starting to format match data for Python engine', array_merge($this->logContext, [
            'action' => 'formatting_match_data',
            'total_pivot_records' => $pivotRecords->count(),
            'step' => 'prepare_python_payload_start'
        ]));

        $matches = [];
        $matchCounter = 0;

        foreach ($pivotRecords as $pivotRecord) {
            $matchCounter++;

            // Get the match from pivot relationship
            $match = $pivotRecord->match;

            if (!$match) {
                Log::warning('⚠️ Match not found in pivot record', array_merge($this->logContext, [
                    'action' => 'match_not_found',
                    'match_number' => $matchCounter,
                    'pivot_id' => $pivotRecord->id,
                    'match_id' => $pivotRecord->match_id
                ]));
                continue;
            }

            Log::debug("📋 Formatting match {$matchCounter} of {$pivotRecords->count()}", array_merge($this->logContext, [
                'action' => 'formatting_single_match',
                'match_number' => $matchCounter,
                'match_id' => $match->id,
                'home_team' => $match->homeTeam->name ?? $match->home_team,
                'away_team' => $match->awayTeam->name ?? $match->away_team,
                'match_date' => $match->match_date ?? 'Unknown'
            ]));

            $formattedMatch = $this->formatMatchData(
                $match,
                $pivotRecord,
                $teamFormService,
                $headToHeadService,
                $marketDataService
            );

            $matches[] = $formattedMatch;

            Log::debug("✅ Match {$matchCounter} formatted successfully", array_merge($this->logContext, [
                'action' => 'match_formatted',
                'match_number' => $matchCounter,
                'match_id' => $match->id,
                'formatted_data_keys' => array_keys($formattedMatch),
                'markets_count' => count($formattedMatch['full_markets'] ?? [])
            ]));
        }

        Log::info('🎯 All matches formatted successfully', array_merge($this->logContext, [
            'action' => 'all_matches_formatted',
            'total_matches_formatted' => count($matches),
            'average_markets_per_match' => array_sum(array_map(function ($m) {
                return count($m['full_markets'] ?? []);
            }, $matches)) / max(1, count($matches))
        ]));

        $riskProfile = $this->determineRiskProfile($masterSlip, $pivotRecords);

        Log::debug('📊 Determining risk profile', array_merge($this->logContext, [
            'action' => 'determining_risk_profile',
            'calculated_risk_profile' => $riskProfile,
            'average_odds' => $pivotRecords->avg('odds'),
            'pivot_records_count' => $pivotRecords->count()
        ]));

        $payload = [
            'master_slip' => [
                'master_slip_id' => $this->jobId,
                'original_master_slip_id' => $this->masterSlipId,
                'stake' => (float) $masterSlip->stake,
                'currency' => $masterSlip->currency ?? 'EUR',
                'created_at' => Carbon::now()->toIso8601String(),
                'total_matches' => count($matches),
                'risk_profile' => $riskProfile,
                'matches' => $matches,
                'metadata' => [
                    'prediction_type' => $this->predictionType,
                    'processing_timestamp' => now()->toISOString(),
                    'laravel_job_id' => $this->jobId,
                    'system_version' => '1.0.0',
                    'pivot_data_used' => true
                ]
            ]
        ];

        Log::debug('🔍 Final payload structure created', array_merge($this->logContext, [
            'action' => 'payload_created',
            'payload_structure' => [
                'master_slip' => array_keys($payload['master_slip'])
            ],
            'first_match_sample' => $payload['master_slip']['matches'][0]['match_id'] ?? 'none'
        ]));

        return $payload;
    }

    protected function formatMatchData(
        $match,
        $pivotRecord,
        TeamFormService $teamFormService,
        HeadToHeadService $headToHeadService,
        MarketDataService $marketDataService
    ): array {
        Log::debug('🔄 Gathering team form data', array_merge($this->logContext, [
            'action' => 'gathering_team_form',
            'match_id' => $match->id,
            'home_team_id' => $match->home_team_id,
            'away_team_id' => $match->away_team_id,
            'form_lookback' => 5
        ]));

        // Get form data
        $homeForm = $teamFormService->getFormData($match->home_team_id, 'home', 5);
        $awayForm = $teamFormService->getFormData($match->away_team_id, 'away', 5);

        Log::debug('📊 Team form data retrieved', array_merge($this->logContext, [
            'action' => 'team_form_retrieved',
            'match_id' => $match->id,
            'home_form_rating' => $homeForm['form_rating'] ?? 'unknown',
            'away_form_rating' => $awayForm['form_rating'] ?? 'unknown',
            'home_form_string' => $homeForm['form_string'] ?? 'unknown',
            'away_form_string' => $awayForm['form_string'] ?? 'unknown'
        ]));

        // Get head-to-head data
        Log::debug('🤝 Gathering head-to-head data', array_merge($this->logContext, [
            'action' => 'gathering_h2h',
            'match_id' => $match->id,
            'home_team' => $match->homeTeam->name ?? $match->home_team,
            'away_team' => $match->awayTeam->name ?? $match->away_team
        ]));

        $h2hData = $headToHeadService->getHeadToHeadData($match->home_team_id, $match->away_team_id, 5);

        Log::debug('✅ Head-to-head data retrieved', array_merge($this->logContext, [
            'action' => 'h2h_retrieved',
            'match_id' => $match->id,
            'h2h_total_matches' => $h2hData['total_matches'] ?? 0,
            'h2h_home_wins' => $h2hData['team1_wins'] ?? 0,
            'h2h_away_wins' => $h2hData['team2_wins'] ?? 0
        ]));

        // Get model inputs
        Log::debug('🧠 Gathering model inputs', array_merge($this->logContext, [
            'action' => 'gathering_model_inputs',
            'match_id' => $match->id
        ]));

        $modelInputs = $marketDataService->getPredictionInputs($match->id);

        Log::debug('✅ Model inputs retrieved', array_merge($this->logContext, [
            'action' => 'model_inputs_retrieved',
            'match_id' => $match->id,
            'model_inputs_count' => count($modelInputs),
            'has_expected_goals' => isset($modelInputs['expected_goals'])
        ]));

        // Extract venue data (it's a string field, not a relationship)
        $venueData = $this->parseVenueData($match->venue);

        $formattedData = [
            'match_id' => $match->id,
            'league' => $match->league ?? 'Unknown',
            'competition' => $match->competition ?? ($match->league ?? 'Unknown'),
            'season' => 'Current', // Default since you don't have season field
            'match_date' => $match->match_date ? $match->match_date->format('Y-m-d') : 'Unknown',
            'match_time' => $match->match_date ? $match->match_date->format('H:i') : 'Unknown',
            'venue' => $venueData['name'],
            'venue_capacity' => 78000, // Default capacity
            'city' => $venueData['city'],
            'country' => $venueData['country'],
            'weather' => $this->parseWeatherData($match->weather_conditions),
            'pitch_type' => 'hybrid', // Default
            'referee' => $match->referee ?? 'Unknown',
            'home_team' => $match->homeTeam->name ?? $match->home_team,
            'away_team' => $match->awayTeam->name ?? $match->away_team,
            'home_team_rank' => $match->homeTeam->current_rank ?? 0,
            'away_team_rank' => $match->awayTeam->current_rank ?? 0,
            'home_team_avg_goals' => $match->homeTeam->avg_goals_scored ?? 1.5,
            'away_team_avg_goals' => $match->awayTeam->avg_goals_scored ?? 1.2,
            'home_form' => $this->formatTeamForm($homeForm),
            'away_form' => $this->formatTeamForm($awayForm),
            'head_to_head' => $this->formatHeadToHead($h2hData, $match->homeTeam, $match->awayTeam),
            'selected_market' => $this->formatSelectedMarketFromPivot($pivotRecord),
            'full_markets' => $this->formatFullMarkets($match),
            'model_inputs' => $this->formatModelInputs($modelInputs, $match, $venueData),
            'pivot_data' => [
                'market' => $pivotRecord->market,
                'selection' => $pivotRecord->selection,
                'odds' => $pivotRecord->odds,
                'match_data' => $pivotRecord->match_data ?? []
            ]
        ];

        Log::debug('✅ Match data formatting complete', array_merge($this->logContext, [
            'action' => 'match_data_formatting_complete',
            'match_id' => $match->id,
            'formatted_fields_count' => count($formattedData),
            'has_weather_data' => !empty($formattedData['weather']),
            'has_model_inputs' => !empty($formattedData['model_inputs']),
            'pivot_odds' => $pivotRecord->odds
        ]));

        return $formattedData;
    }

    protected function parseVenueData($venueString): array
    {
        // Parse venue string (e.g., "Old Trafford, Manchester, England (75000)")
        if (empty($venueString)) {
            return [
                'name' => 'Unknown',
                'capacity' => 0,
                'city' => 'Unknown',
                'country' => 'Unknown'
            ];
        }

        // Try to extract capacity if in parentheses
        $capacity = 0;
        $name = $venueString;

        if (preg_match('/\((\d+)\)/', $venueString, $matches)) {
            $capacity = (int) $matches[1];
            $name = trim(preg_replace('/\(\d+\)/', '', $venueString));
        }

        // Try to parse city and country from comma separation
        $parts = explode(',', $name);
        $city = 'Unknown';
        $country = 'Unknown';

        if (count($parts) >= 2) {
            $city = trim($parts[1]);
            $country = count($parts) >= 3 ? trim($parts[2]) : trim($parts[1]);
            $name = trim($parts[0]);
        }

        return [
            'name' => $name,
            'capacity' => $capacity,
            'city' => $city,
            'country' => $country
        ];
    }

    protected function parseWeatherData($weatherString): array
    {
        if (empty($weatherString)) {
            return $this->getDefaultWeather();
        }

        // If weather is stored as JSON string
        if (is_string($weatherString) && strpos($weatherString, '{') === 0) {
            $weather = json_decode($weatherString, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'temperature' => $weather['temperature'] ?? 18.0,
                    'condition' => $weather['condition'] ?? 'clear',
                    'wind_speed' => $weather['wind_speed'] ?? 10.0,
                ];
            }
        }

        // If weather is a simple string
        return [
            'temperature' => 18.0,
            'condition' => strtolower($weatherString),
            'wind_speed' => 10.0,
        ];
    }

    protected function formatSelectedMarketFromPivot($pivotRecord): array
    {
        Log::debug('🎯 Formatting selected market from pivot', array_merge($this->logContext, [
            'action' => 'formatting_selected_market_pivot',
            'pivot_id' => $pivotRecord->id,
            'pivot_market' => $pivotRecord->market,
            'pivot_selection' => $pivotRecord->selection,
            'pivot_odds' => $pivotRecord->odds
        ]));

        return [
            'market_type' => $pivotRecord->market ?? '1X2',
            'selection' => $pivotRecord->selection ?? 'Home',
            'odds' => (float) ($pivotRecord->odds ?? 1.85),
            'implied_probability' => $this->calculateImpliedProbability($pivotRecord->odds ?? 1.85),
            'confidence_rating' => 7.0,
            'source' => 'pivot_table'
        ];
    }

    protected function calculateImpliedProbability($odds): float
    {
        if ($odds <= 1) {
            return 1.0;
        }
        return round(1 / $odds, 3);
    }

    protected function formatTeamForm(array $formData): array
    {
        Log::debug('📈 Formatting team form data', array_merge($this->logContext, [
            'action' => 'formatting_team_form',
            'form_data_keys' => array_keys($formData),
            'has_raw_form' => isset($formData['last_matches'])
        ]));

        $formatted = [
            'form_string' => $formData['form_string'] ?? 'WWWWW',
            'matches_played' => $formData['matches_played'] ?? 5,
            'wins' => $formData['wins'] ?? 5,
            'draws' => $formData['draws'] ?? 0,
            'losses' => $formData['losses'] ?? 0,
            'avg_goals_scored' => $formData['avg_goals_scored'] ?? 2.0,
            'avg_goals_conceded' => $formData['avg_goals_conceded'] ?? 0.5,
            'form_rating' => $formData['form_rating'] ?? 9.0,
            'form_momentum' => $formData['form_momentum'] ?? 'positive',
            'raw_form' => array_map(function ($match) {
                return [
                    'result' => $match['result'] ?? 'W',
                    'score' => $match['score'] ?? '2-0',
                    'opponent' => $match['opponent'] ?? 'Unknown',
                ];
            }, $formData['last_matches'] ?? array_fill(0, 5, [])),
        ];

        Log::debug('✅ Team form formatted', array_merge($this->logContext, [
            'action' => 'team_form_formatted',
            'form_string' => $formatted['form_string'],
            'form_rating' => $formatted['form_rating'],
            'raw_form_count' => count($formatted['raw_form'])
        ]));

        return $formatted;
    }

    protected function formatHeadToHead(array $h2hData, $homeTeam, $awayTeam): array
    {
        Log::debug('🤝 Formatting head-to-head data', array_merge($this->logContext, [
            'action' => 'formatting_h2h',
            'home_team' => $homeTeam->name ?? 'Unknown',
            'away_team' => $awayTeam->name ?? 'Unknown',
            'h2h_data_keys' => array_keys($h2hData)
        ]));

        $totalMatches = count($h2hData['last_meetings'] ?? []);

        $formatted = [
            'total_matches' => $h2hData['total_matches'] ?? $totalMatches,
            'home_wins' => $h2hData['team1_wins'] ?? ($totalMatches > 0 ? ceil($totalMatches * 0.6) : 0),
            'away_wins' => $h2hData['team2_wins'] ?? ($totalMatches > 0 ? ceil($totalMatches * 0.2) : 0),
            'draws' => $h2hData['draws'] ?? ($totalMatches > 0 ? ceil($totalMatches * 0.2) : 0),
            'avg_goals_per_match' => $h2hData['avg_goals'] ?? 2.5,
            'last_5_meetings' => array_map(function ($meeting) {
                return [
                    'date' => $meeting['date'] ?? '2024-01-01',
                    'score' => $meeting['score'] ?? '2-0',
                    'venue' => $meeting['venue'] ?? 'Unknown',
                    'winner' => $meeting['winner'] ?? 'Home',
                ];
            }, $h2hData['last_meetings'] ?? array_fill(0, min(5, $totalMatches), [])),
        ];

        Log::debug('✅ Head-to-head data formatted', array_merge($this->logContext, [
            'action' => 'h2h_formatted',
            'total_matches' => $formatted['total_matches'],
            'home_win_rate' => $formatted['total_matches'] > 0 ?
                round($formatted['home_wins'] / $formatted['total_matches'], 2) : 0,
            'last_meetings_count' => count($formatted['last_5_meetings'])
        ]));

        return $formatted;
    }

    //TODO: make sure match instance is passed as parameter 
    protected function formatFullMarkets($match): array
    {
        // Log::debug('💰 Formatting full markets', array_merge($this->logContext, [
        //     'action' => 'formatting_full_markets',
        //     'total_markets' => $markets->count(),
        //     'market_types' => $markets->pluck('market_type')->unique()->toArray()
        // ]));
        $markets = [];

        if ($match->matchMarkets && count($match->matchMarkets) > 0) {
            foreach ($match->matchMarkets as $matchMarket) {
                $market = $matchMarket->market;

                $markets[] = [
                    'market_name' => $market->name,
                    'market_code' => $market->code,
                    'market_type' => $market->market_type,
                    'outcomes' => $this->formatMarketOutcomes($matchMarket),
                ];
            }
        }

        return $markets;
    }

    protected function formatMarketOutcomes($matchMarket): array
    {
        $outcomes = [];

        // Get actual outcomes from database
        $marketOutcomes = MatchMarketOutcome::where('match_market_id', $matchMarket->id)->get();

        foreach ($marketOutcomes as $outcome) {
            $outcomes[] = [
                'outcome_key' => $outcome->outcome_key,
                'label' => $outcome->label,
                'odds' => (float) $outcome->odds,
                'is_default' => (bool) $outcome->is_default,
            ];
        }

        // If no outcomes, return sensible defaults
        if (empty($outcomes)) {
            return $this->getDefaultMarketOutcomes($matchMarket->market->market_type);
        }

        return $outcomes;
    }

    protected function getDefaultMarketOutcomes(string $marketType): array
    {
        $defaults = [
            '1X2' => [
                ['selection' => 'Home', 'odds' => 1.85, 'implied_probability' => 0.54],
                ['selection' => 'Draw', 'odds' => 3.75, 'implied_probability' => 0.267],
                ['selection' => 'Away', 'odds' => 4.20, 'implied_probability' => 0.238],
            ],
            'correct_score' => [
                ['score' => '1-0', 'odds' => 8.5, 'implied_probability' => 0.118],
                ['score' => '2-0', 'odds' => 7.0, 'implied_probability' => 0.143],
            ],
            // ... add other market types
        ];

        return $defaults[$marketType] ?? [];
    }

    protected function groupMarketsByType($markets)
    {
        $groups = [];

        foreach ($markets as $market) {
            $marketType = $this->normalizeMarketType($market->market_type);
            if (!isset($groups[$marketType])) {
                $groups[$marketType] = [];
            }
            $groups[$marketType][] = $market;
        }

        return $groups;
    }

    protected function normalizeMarketType(?string $marketType): string
    {
        if (!$marketType) {
            return 'unknown'; // Always return a string, not null
        }

        $normalized = strtolower(trim($marketType));

        $marketTypeMappings = [
            '1x2' => 'match_result',
            'match_result' => 'match_result',
            'win_draw_win' => 'match_result',
            'correct_score' => 'correct_score',
            'cs' => 'correct_score',
            'asian_handicap' => 'asian_handicap',
            'ah' => 'asian_handicap',
            'handicap' => 'asian_handicap',
            'btts' => 'both_teams_to_score',
            'both_teams_to_score' => 'both_teams_to_score',
            'over_under' => 'over_under',
            'total_goals' => 'over_under',
            'ou' => 'over_under',
            'double_chance' => 'double_chance',
            'dc' => 'double_chance',
            'halftime' => 'halftime_result',
            'ht_result' => 'halftime_result',
            'corners' => 'corners',
            'total_corners' => 'corners',
        ];

        return $marketTypeMappings[$normalized] ?? $normalized;
    }

    protected function getRequiredMarketTypes(): array
    {
        $types = [
            'correct_score',
            'asian_handicap',
            'both_teams_to_score',
            'over_under',
            'halftime_result',
            'corners',
        ];

        Log::debug('📋 Getting required market types', array_merge($this->logContext, [
            'action' => 'getting_required_market_types',
            'types_count' => count($types),
            'types_list' => $types
        ]));

        return $types;
    }

    protected function formatMarketOptions($markets, $marketType): array
    {
        if (empty($markets)) {
            Log::debug('⚡ No markets found, using defaults', array_merge($this->logContext, [
                'action' => 'using_default_markets',
                'market_type' => $marketType
            ]));

            return $this->getDefaultMarketOptions($marketType);
        }

        Log::debug('🛒 Formatting market options', array_merge($this->logContext, [
            'action' => 'formatting_market_options',
            'market_type' => $marketType,
            'options_count' => count($markets)
        ]));

        $options = [];
        foreach ($markets as $index => $market) {
            $option = [
                'odds' => (float) $market->odds,
                'implied_probability' => (float) $market->implied_probability,
            ];

            switch ($marketType) {
                case 'correct_score':
                    $option['score'] = $market->selection;
                    break;
                case 'asian_handicap':
                    $option['handicap'] = $market->selection;
                    break;
                case 'both_teams_to_score':
                    $option['selection'] = $market->selection;
                    break;
                case 'over_under':
                    $option['line'] = $market->selection;
                    break;
                case 'halftime_result':
                    $option['selection'] = $market->selection;
                    break;
                case 'corners':
                    $option['type'] = $market->selection_type ?? 'total';
                    $option['line'] = $market->line_value ?? '9.5';
                    break;
                default:
                    $option['selection'] = $market->selection;
            }

            $options[] = $option;

            Log::debug("📊 Market option {$index} formatted", array_merge($this->logContext, [
                'action' => 'market_option_formatted',
                'market_type' => $marketType,
                'option_index' => $index,
                'odds' => $option['odds'],
                'key_field' => $option['score'] ?? $option['handicap'] ?? $option['selection'] ?? 'unknown'
            ]));
        }

        return $options;
    }

    protected function getDefaultMarketOptions($marketType): array
    {
        $defaults = [
            'correct_score' => [
                ['score' => '1-0', 'odds' => 8.5, 'implied_probability' => 0.118],
                ['score' => '2-0', 'odds' => 7.0, 'implied_probability' => 0.143],
            ],
            'asian_handicap' => [
                ['handicap' => 'Home -0.5', 'odds' => 1.85, 'implied_probability' => 0.541],
                ['handicap' => 'Away +0.5', 'odds' => 2.05, 'implied_probability' => 0.488],
            ],
            'both_teams_to_score' => [
                ['selection' => 'Yes', 'odds' => 1.95, 'implied_probability' => 0.513],
                ['selection' => 'No', 'odds' => 1.85, 'implied_probability' => 0.541],
            ],
            'over_under' => [
                ['line' => 'Over 2.5', 'odds' => 1.85, 'implied_probability' => 0.541],
                ['line' => 'Under 2.5', 'odds' => 2.00, 'implied_probability' => 0.500],
            ],
            'halftime_result' => [
                ['selection' => 'Home', 'odds' => 2.50, 'implied_probability' => 0.400],
                ['selection' => 'Draw', 'odds' => 2.20, 'implied_probability' => 0.455],
                ['selection' => 'Away', 'odds' => 3.50, 'implied_probability' => 0.286],
            ],
            'corners' => [
                ['type' => 'total_over', 'line' => '9.5', 'odds' => 1.85, 'implied_probability' => 0.541],
                ['type' => 'total_under', 'line' => '9.5', 'odds' => 1.95, 'implied_probability' => 0.513],
            ],
        ];

        $options = $defaults[$marketType] ?? [['selection' => 'default', 'odds' => 1.85, 'implied_probability' => 0.54]];

        Log::debug('🎯 Returning default market options', array_merge($this->logContext, [
            'action' => 'returning_default_markets',
            'market_type' => $marketType,
            'options_count' => count($options),
            'has_defaults' => isset($defaults[$marketType])
        ]));

        return $options;
    }

    protected function formatModelInputs($inputs, $match, $venueData): array
    {
        Log::debug('🧮 Formatting model inputs', array_merge($this->logContext, [
            'action' => 'formatting_model_inputs',
            'match_id' => $match->id
        ]));

        // Calculate venue advantage based on venue data
        $venueAdvantage = $this->calculateVenueAdvantage($match, $venueData);

        $formatted = [
            'home_form_weight' => $inputs['home_form_weight'] ?? 0.35,
            'away_form_weight' => $inputs['away_form_weight'] ?? 0.25,
            'h2h_weight' => $inputs['h2h_weight'] ?? 0.15,
            'venue_advantage' => $venueAdvantage,
            'weather_impact' => $this->calculateWeatherImpactFromData($match->weather_conditions),
            'referee_bias' => $inputs['referee_bias'] ?? 0.01,
            'expected_goals' => $inputs['expected_goals'] ?? 2.5,
            'home_xg' => $inputs['home_xg'] ?? 1.5,
            'away_xg' => $inputs['away_xg'] ?? 1.0,
            'volatility_score' => $inputs['volatility_score'] ?? 3.0,
        ];

        Log::debug('✅ Model inputs formatted', array_merge($this->logContext, [
            'action' => 'model_inputs_formatted',
            'match_id' => $match->id,
            'total_weight' => ($formatted['home_form_weight'] + $formatted['away_form_weight'] + $formatted['h2h_weight']),
            'venue_advantage' => $formatted['venue_advantage'],
            'weather_impact' => $formatted['weather_impact']
        ]));

        return $formatted;
    }

    protected function calculateVenueAdvantage($match, $venueData): float
    {
        // Simple venue advantage calculation
        $advantage = 0.70; // Base home advantage

        // Adjust based on capacity (larger stadium = more advantage)
        if ($venueData['capacity'] > 50000) {
            $advantage += 0.05;
        } elseif ($venueData['capacity'] > 30000) {
            $advantage += 0.03;
        }

        return round($advantage, 3);
    }

    protected function calculateWeatherImpactFromData($weatherString): float
    {
        $weather = $this->parseWeatherData($weatherString);
        return $this->calculateWeatherImpact($weather);
    }

    protected function calculateWeatherImpact(array $weather): float
    {
        $impact = 0;

        // Temperature impact (optimal: 15-20°C)
        $temp = $weather['temperature'];
        if ($temp < 5 || $temp > 30) {
            $impact += 0.03;
        } elseif ($temp < 10 || $temp > 25) {
            $impact += 0.02;
        } elseif ($temp < 15 || $temp > 20) {
            $impact += 0.01;
        }

        // Wind impact
        $wind = $weather['wind_speed'];
        if ($wind > 25) {
            $impact += 0.03;
        } elseif ($wind > 15) {
            $impact += 0.02;
        } elseif ($wind > 10) {
            $impact += 0.01;
        }

        // Condition impact
        $condition = $weather['condition'];
        if (in_array($condition, ['heavy_rain', 'rain', 'snow', 'thunderstorm'])) {
            $impact += 0.03;
        } elseif (in_array($condition, ['light_rain', 'fog', 'mist'])) {
            $impact += 0.02;
        } elseif ($condition === 'cloudy') {
            $impact += 0.01;
        }

        return round($impact, 3);
    }

    protected function getDefaultWeather(): array
    {
        return [
            'temperature' => round(18.0 + rand(-5, 5), 1),
            'condition' => $this->getRandomCondition(['clear' => 50, 'partly_cloudy' => 30, 'cloudy' => 15, 'light_rain' => 5]),
            'wind_speed' => round(10.0 + rand(-3, 3), 1),
        ];
    }

    protected function getRandomCondition(array $weights): string
    {
        $total = array_sum($weights);
        $random = rand(1, $total);
        $current = 0;

        foreach ($weights as $condition => $weight) {
            $current += $weight;
            if ($random <= $current) {
                return $condition;
            }
        }

        return 'clear';
    }

    protected function determineRiskProfile($masterSlip, $pivotRecords): string
    {
        Log::debug('🎲 Determining risk profile', array_merge($this->logContext, [
            'action' => 'determining_risk_profile_start',
            'master_slip_id' => $masterSlip->id,
            'pivot_records_count' => $pivotRecords->count()
        ]));

        $avgOdds = $pivotRecords->avg('odds');

        $riskProfile = 'medium';
        if ($avgOdds > 3.0) {
            $riskProfile = 'high';
        } elseif ($avgOdds > 2.0) {
            $riskProfile = 'medium';
        } else {
            $riskProfile = 'low';
        }

        Log::info('📊 Risk profile determined', array_merge($this->logContext, [
            'action' => 'risk_profile_determined',
            'average_odds' => $avgOdds,
            'risk_profile' => $riskProfile,
            'odds_range' => [
                'min' => $pivotRecords->min('odds'),
                'max' => $pivotRecords->max('odds')
            ]
        ]));

        return $riskProfile;
    }

    protected function dispatchToPythonEngine(int $masterSlipId, array $payload): array
    {
        Log::info('🚀 Dispatching to Python engine', array_merge($this->logContext, [
            'action' => 'dispatching_to_python_start',
            'payload_size_kb' => round(strlen(json_encode($payload)) / 1024, 2),
            'endpoint' => 'http://localhost:5000/generate-slips',
            'queue' => 'python_requests',
            'masterslipId' => $masterSlipId
        ]));

        // Dispatch to ProcessPythonRequest for actual engine communication
        $pythonResponse = ProcessPythonRequest::dispatch(
            $masterSlipId, // Ensure it's an integer
            $payload,
            $this->predictionType,
            $this->riskProfile,
            // $this->jobId,
            // 'http://localhost:5000/generate-slips',
        )->onQueue('python_requests');

        Log::info('✅ Dispatched to Python engine successfully', array_merge($this->logContext, [
            'action' => 'dispatched_to_python',
            'endpoint' => 'http://localhost:5000/generate-slips',
            'prediction_type' => $this->predictionType,
            'dispatched_at' => now()->toISOString()
        ]));

        $fullresponse = StoreGeneratedSlips::dispatch($this->masterSlipId, $pythonResponse)
            ->onQueue('slip_storage');

        return ['responseFromDispatch' => $fullresponse];
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('❌ Handling job failure', array_merge($this->logContext, [
            'action' => 'handling_failure',
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'failed_at' => now()->toISOString(),
            'attempts_made' => $this->attempts(),
            'max_attempts' => $this->tries
        ]));

        $masterSlip = MasterSlip::find($this->masterSlipId);
        if ($masterSlip) {
            Log::warning('🔄 Updating master slip status to failed', array_merge($this->logContext, [
                'action' => 'updating_master_slip_failed',
                'master_slip_id' => $masterSlip->id,
                'previous_status' => $masterSlip->status
            ]));

            $masterSlip->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
                'last_updated_at' => now(),
                'failure_metadata' => [
                    'error_type' => get_class($e),
                    'error_code' => $e->getCode(),
                    'failed_at' => now()->toISOString(),
                    'job_attempts' => $this->attempts()
                ]
            ]);

            Log::info('✅ Master slip status updated to failed', array_merge($this->logContext, [
                'action' => 'master_slip_updated_failed',
                'master_slip_id' => $masterSlip->id,
                'new_status' => 'failed'
            ]));
        } else {
            Log::critical('🚨 Master slip not found during failure handling', array_merge($this->logContext, [
                'action' => 'master_slip_not_found',
                'master_slip_id' => $this->masterSlipId
            ]));
        }

        $this->fail($e);
    }

    protected function getSimplifiedTrace(\Exception $e): array
    {
        $trace = $e->getTrace();
        $simplified = [];

        foreach (array_slice($trace, 0, 5) as $index => $item) {
            $simplified[] = [
                'file' => $item['file'] ?? 'unknown',
                'line' => $item['line'] ?? 'unknown',
                'function' => $item['function'] ?? 'unknown',
                'class' => $item['class'] ?? 'unknown'
            ];
        }

        return $simplified;
    }

    public function failed(\Throwable $exception)
    {
        Log::critical('💥 GenerateAlternativeSlipsJob failed completely', array_merge($this->logContext, [
            'action' => 'job_completely_failed',
            'final_error_message' => $exception->getMessage(),
            'final_error_type' => get_class($exception),
            'total_attempts_made' => $this->attempts(),
            'max_attempts_allowed' => $this->tries,
            'final_failure_time' => now()->toISOString(),
            'job_duration' => microtime(true) - LARAVEL_START,
            'memory_peak_usage' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB'
        ]));

        $this->handleFailure($exception);
    }





}

/***
 * 
 * 
 usage
 // Dispatch the job
GenerateAlternativeSlipsJob::dispatch(
    $masterSlipId,
    'monte_carlo',  // prediction type
    'high',         // risk profile
    'alt_slip_001'  // optional job ID
)->onQueue('slip_generation');

// This will:
// 1. Load master slip data from database
// 2. Format it exactly as Python engine expects
// 3. Dispatch to ProcessPythonRequest for actual execution
// 4. Update master slip status

*/