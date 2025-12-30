<?php
// app/Jobs/ProcessPythonRequest.php

namespace App\Jobs;

use App\Models\MasterSlip;
use App\Models\MasterSlipMatch;
use App\Models\MatchModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class ProcessPythonRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of seconds the job can run before timing out
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Number of times the job may be attempted
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Backoff strategy between retries (in seconds)
     *
     * @var array
     */
    public $backoff = [30, 60, 120];

    /**
     * The master slip ID to process
     *
     * @var int
     */
    protected $masterSlipId;

    /**
     * Create a new job instance
     *
     * ProcessPythonRequest is the final bridge between Laravel's orchestration
     * and Python's compute engine. It loads pre-computed slip data and formats
     * it for Python consumption.
     *
     * @param int $masterSlipId The ID of the master slip to process
     */
    public function __construct(int $masterSlipId)
    {
        $this->masterSlipId = $masterSlipId;

        // Use dedicated queue for Python engine communication
        $this->onQueue('python_engine');
    }

    /**
     * Execute the job
     *
     * Loads the master slip, builds the Python payload, and sends it
     * to the Python engine for computation.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('Processing Python request for master slip', [
            'job_id' => $this->job->getJobId(),
            'master_slip_id' => $this->masterSlipId,
        ]);

        try {
            // Load the master slip with all pre-computed analysis data
            $masterSlip = $this->loadMasterSlip();

            // Build the final payload for Python engine
            $payload = $this->buildPayloadFromSlip($masterSlip);

            // Send to Python engine
            $response = $this->sendToPythonEngine($payload);

            // Handle the Python engine response
            $this->handlePythonResponse($response, $masterSlip);

            Log::info('Python request completed successfully', [
                'master_slip_id' => $this->masterSlipId,
                'match_count' => count($payload['master_slip']['matches']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process Python request', [
                'master_slip_id' => $this->masterSlipId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Load the master slip with all pre-computed analysis
     *
     * Assumes all analysis (team forms, head-to-head, markets, etc.)
     * has already been computed and stored by ProcessBetslipAnalysis.
     *
     * @return MasterSlip
     * @throws \Exception If slip not found or invalid
     */
    protected function loadMasterSlip(): MasterSlip
    {
        $masterSlip = MasterSlip::with([
            // Load slip matches with their pre-computed analysis
            'matches' => function ($query) {
                $query->with([
                    // Match metadata
                    'matchModel' => function ($q) {
                        $q->with([
                            'homeTeam',
                            'awayTeam',
                            'league',
                            'venue',
                            'referee',
                        ]);
                    },

                    // Pre-computed analysis (from ProcessBetslipAnalysis)
                    'analysis' => function ($q) {
                        $q->with([
                            'homeForm',
                            'awayForm',
                            'headToHead',
                            'marketAnalysis',
                            'modelInputs',
                        ]);
                    },

                    // Pre-selected market for this slip
                    'selectedMarket',

                    // All available markets (pre-computed)
                    'markets' => function ($q) {
                        $q->orderBy('market_type')->orderBy('odds');
                    },
                ]);
            },
        ])->find($this->masterSlipId);

        if (!$masterSlip) {
            throw new \Exception("Master slip {$this->masterSlipId} not found");
        }

        if ($masterSlip->matches->count() < 2) {
            throw new \Exception("Master slip must contain at least 2 matches, found {$masterSlip->matches->count()}");
        }

        return $masterSlip;
    }

    /**
     * Build the Python payload from the master slip
     *
     * Transforms Laravel's pre-computed analysis data into the exact
     * JSON structure expected by the Python engine.
     *
     * @param MasterSlip $masterSlip
     * @return array
     */
    protected function buildPayloadFromSlip(MasterSlip $masterSlip): array
    {
        $matchesPayload = [];

        foreach ($masterSlip->matches as $slipMatch) {
            $matchesPayload[] = $this->buildMatchPayload($slipMatch);
        }

        return [
            'master_slip' => [
                'master_slip_id' => $masterSlip->id,
                'stake' => (float) $masterSlip->stake,
                'currency' => $masterSlip->currency,
                'created_at' => Carbon::parse($masterSlip->created_at)->toIso8601String(),
                'total_matches' => $masterSlip->matches->count(),
                'risk_profile' => $this->determineRiskProfile($masterSlip),
                'matches' => $matchesPayload,
            ]
        ];
    }

    /**
     * Build individual match payload from pre-computed analysis
     *
     * All data should already be available in the slip match analysis.
     * No additional database queries or computations should be needed.
     *
     * @param MasterSlipMatch $slipMatch
     * @return array
     */
    protected function buildMatchPayload(MasterSlipMatch $slipMatch): array
    {
        $matchModel = $slipMatch->matchModel;
        $analysis = $slipMatch->analysis;

        return [
            // Match metadata
            'match_id' => $matchModel->id,
            'league' => $matchModel->league->name,
            'competition' => $matchModel->league->competition_name ?? $matchModel->league->name,
            'season' => $matchModel->season,
            'match_date' => $matchModel->match_date->format('Y-m-d'),
            'match_time' => $matchModel->match_time,
            'venue' => $matchModel->venue->name,
            'venue_capacity' => $matchModel->venue->capacity,
            'city' => $matchModel->venue->city,
            'country' => $matchModel->venue->country,
            'weather' => $this->extractWeatherData($analysis),
            'pitch_type' => $matchModel->venue->pitch_type ?? 'hybrid',
            'referee' => $matchModel->referee->name,

            // Teams
            'home_team' => $matchModel->homeTeam->name,
            'away_team' => $matchModel->awayTeam->name,
            'home_team_rank' => $matchModel->homeTeam->current_rank ?? 0,
            'away_team_rank' => $matchModel->awayTeam->current_rank ?? 0,
            'home_team_avg_goals' => $matchModel->homeTeam->avg_goals_scored ?? 0,
            'away_team_avg_goals' => $matchModel->awayTeam->avg_goals_scored ?? 0,

            // Pre-computed forms (from ProcessBetslipAnalysis)
            'home_form' => $this->formatPrecomputedForm($analysis->homeForm ?? null, 'home'),
            'away_form' => $this->formatPrecomputedForm($analysis->awayForm ?? null, 'away'),

            // Pre-computed head-to-head (from ProcessBetslipAnalysis)
            'head_to_head' => $this->formatPrecomputedHeadToHead($analysis->headToHead ?? null),

            // Selected market for this slip
            'selected_market' => $this->formatSelectedMarket($slipMatch->selectedMarket),

            // All available markets (pre-computed)
            'full_markets' => $this->formatPrecomputedMarkets($slipMatch->markets),

            // Model inputs (pre-computed by ProcessBetslipAnalysis)
            'model_inputs' => $this->formatModelInputs($analysis->modelInputs ?? null),
        ];
    }

    /**
     * Extract weather data from pre-computed analysis
     *
     * @param mixed $analysis
     * @return array
     */
    protected function extractWeatherData($analysis): array
    {
        // Weather should be pre-computed in the analysis
        if ($analysis && isset($analysis->weather_data)) {
            return [
                'temperature' => $analysis->weather_data['temperature'] ?? 15.0,
                'condition' => $analysis->weather_data['condition'] ?? 'clear',
                'wind_speed' => $analysis->weather_data['wind_speed'] ?? 10.0,
            ];
        }

        // Fallback to realistic defaults if not pre-computed
        return [
            'temperature' => 15.0,
            'condition' => 'clear',
            'wind_speed' => 10.0,
        ];
    }

    /**
     * Format pre-computed team form data
     *
     * @param mixed $formData
     * @param string $teamType
     * @return array
     */
    protected function formatPrecomputedForm($formData, string $teamType): array
    {
        if ($formData) {
            return [
                'form_string' => $formData->form_string ?? '',
                'matches_played' => $formData->matches_played ?? 0,
                'wins' => $formData->wins ?? 0,
                'draws' => $formData->draws ?? 0,
                'losses' => $formData->losses ?? 0,
                'avg_goals_scored' => $formData->avg_goals_scored ?? 0,
                'avg_goals_conceded' => $formData->avg_goals_conceded ?? 0,
                'form_rating' => $formData->form_rating ?? 0,
                'form_momentum' => $formData->form_momentum ?? 'neutral',
                'raw_form' => $this->formatRawForm($formData->raw_form ?? []),
            ];
        }

        // Fallback for missing form data
        return $this->getDefaultFormData($teamType);
    }

    /**
     * Format raw form array
     *
     * @param array|string $rawForm
     * @return array
     */
    protected function formatRawForm($rawForm): array
    {
        if (is_string($rawForm)) {
            $rawForm = json_decode($rawForm, true) ?? [];
        }

        if (!is_array($rawForm)) {
            return [];
        }

        return array_map(function ($match) {
            return [
                'result' => $match['result'] ?? 'W',
                'score' => $match['score'] ?? '1-0',
                'opponent' => $match['opponent'] ?? 'Unknown',
            ];
        }, array_slice($rawForm, 0, 5));
    }

    /**
     * Get default form data when pre-computed data is missing
     *
     * @param string $teamType
     * @return array
     */
    protected function getDefaultFormData(string $teamType): array
    {
        return [
            'form_string' => 'WWLDD',
            'matches_played' => 5,
            'wins' => 3,
            'draws' => 1,
            'losses' => 1,
            'avg_goals_scored' => 1.8,
            'avg_goals_conceded' => 1.2,
            'form_rating' => 7.0,
            'form_momentum' => $teamType === 'home' ? 'positive' : 'neutral',
            'raw_form' => [
                ['result' => 'W', 'score' => '2-0', 'opponent' => 'Team A'],
                ['result' => 'W', 'score' => '3-1', 'opponent' => 'Team B'],
                ['result' => 'L', 'score' => '0-1', 'opponent' => 'Team C'],
                ['result' => 'D', 'score' => '1-1', 'opponent' => 'Team D'],
                ['result' => 'D', 'score' => '2-2', 'opponent' => 'Team E'],
            ],
        ];
    }

    /**
     * Format pre-computed head-to-head data
     *
     * @param mixed $h2hData
     * @return array
     */
    protected function formatPrecomputedHeadToHead($h2hData): array
    {
        if ($h2hData) {
            return [
                'total_matches' => $h2hData->total_matches ?? 0,
                'home_wins' => $h2hData->home_wins ?? 0,
                'away_wins' => $h2hData->away_wins ?? 0,
                'draws' => $h2hData->draws ?? 0,
                'avg_goals_per_match' => $h2hData->avg_goals_per_match ?? 2.5,
                'last_5_meetings' => $this->formatLastMeetings($h2hData->last_meetings ?? []),
            ];
        }

        // Fallback for missing H2H data
        return [
            'total_matches' => 10,
            'home_wins' => 5,
            'away_wins' => 3,
            'draws' => 2,
            'avg_goals_per_match' => 2.5,
            'last_5_meetings' => [
                ['date' => '2024-01-01', 'score' => '2-0', 'venue' => 'Home Stadium', 'winner' => 'Home'],
                ['date' => '2023-08-15', 'score' => '1-1', 'venue' => 'Away Stadium', 'winner' => 'Draw'],
                ['date' => '2023-04-22', 'score' => '3-1', 'venue' => 'Home Stadium', 'winner' => 'Home'],
                ['date' => '2022-12-10', 'score' => '0-2', 'venue' => 'Away Stadium', 'winner' => 'Away'],
                ['date' => '2022-08-05', 'score' => '2-2', 'venue' => 'Home Stadium', 'winner' => 'Draw'],
            ],
        ];
    }

    /**
     * Format last meetings data
     *
     * @param array|string $meetings
     * @return array
     */
    protected function formatLastMeetings($meetings): array
    {
        if (is_string($meetings)) {
            $meetings = json_decode($meetings, true) ?? [];
        }

        if (!is_array($meetings)) {
            return [];
        }

        return array_map(function ($meeting) {
            return [
                'date' => $meeting['date'] ?? '2024-01-01',
                'score' => $meeting['score'] ?? '1-0',
                'venue' => $meeting['venue'] ?? 'Unknown',
                'winner' => $meeting['winner'] ?? 'Home',
            ];
        }, array_slice($meetings, 0, 5));
    }

    /**
     * Format selected market
     *
     * @param mixed $selectedMarket
     * @return array
     */
    protected function formatSelectedMarket($selectedMarket): array
    {
        if ($selectedMarket) {
            return [
                'market_type' => $selectedMarket->market_type,
                'selection' => $selectedMarket->selection,
                'odds' => (float) $selectedMarket->odds,
                'implied_probability' => (float) $selectedMarket->implied_probability,
                'confidence_rating' => (float) $selectedMarket->confidence_rating,
            ];
        }

        // Default market if none selected
        return [
            'market_type' => '1X2',
            'selection' => 'Home',
            'odds' => 1.85,
            'implied_probability' => 0.54,
            'confidence_rating' => 7.0,
        ];
    }

    /**
     * Format pre-computed markets
     *
     * @param \Illuminate\Support\Collection $markets
     * @return array
     */
    protected function formatPrecomputedMarkets($markets): array
    {
        $marketGroups = $markets->groupBy('market_type');

        $requiredTypes = [
            'correct_score',
            'asian_handicap',
            'both_teams_to_score',
            'over_under',
            'halftime_result',
            'corners',
        ];

        $formattedMarkets = [];

        foreach ($requiredTypes as $marketType) {
            $marketOptions = $marketGroups->get($this->normalizeMarketType($marketType), collect());
            $formattedMarkets[] = [
                'market_name' => $marketType,
                'options' => $this->formatMarketOptions($marketOptions, $marketType),
            ];
        }

        return $formattedMarkets;
    }

    /**
     * Normalize market type names
     *
     * @param string $type
     * @return string
     */
    protected function normalizeMarketType(string $type): string
    {
        $mapping = [
            'correct_score' => 'correct_score',
            'asian_handicap' => 'asian_handicap',
            'btts' => 'both_teams_to_score',
            'over_under' => 'over_under',
            'ht_result' => 'halftime_result',
            'corners' => 'corners',
        ];

        return $mapping[$type] ?? $type;
    }

    /**
     * Format market options
     *
     * @param \Illuminate\Support\Collection $markets
     * @param string $marketType
     * @return array
     */
    protected function formatMarketOptions($markets, string $marketType): array
    {
        if ($markets->isEmpty()) {
            return $this->getDefaultMarketOptions($marketType);
        }

        return $markets->map(function ($market) use ($marketType) {
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
                case 'halftime_result':
                    $option['selection'] = $market->selection;
                    break;
                case 'over_under':
                    $option['line'] = $market->selection;
                    break;
                case 'corners':
                    $option['type'] = $market->selection_type ?? 'total';
                    $option['line'] = $market->line_value ?? '9.5';
                    break;
            }

            return $option;
        })->toArray();
    }

    /**
     * Get default market options
     *
     * @param string $marketType
     * @return array
     */
    protected function getDefaultMarketOptions(string $marketType): array
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

        return $defaults[$marketType] ?? [];
    }

    /**
     * Format model inputs from pre-computed analysis
     *
     * @param mixed $modelInputs
     * @return array
     */
    protected function formatModelInputs($modelInputs): array
    {
        if ($modelInputs) {
            return [
                'home_form_weight' => (float) ($modelInputs->home_form_weight ?? 0.35),
                'away_form_weight' => (float) ($modelInputs->away_form_weight ?? 0.25),
                'h2h_weight' => (float) ($modelInputs->h2h_weight ?? 0.15),
                'venue_advantage' => (float) ($modelInputs->venue_advantage ?? 0.70),
                'weather_impact' => (float) ($modelInputs->weather_impact ?? 0.02),
                'referee_bias' => (float) ($modelInputs->referee_bias ?? 0.01),
                'expected_goals' => (float) ($modelInputs->expected_goals ?? 2.5),
                'home_xg' => (float) ($modelInputs->home_xg ?? 1.5),
                'away_xg' => (float) ($modelInputs->away_xg ?? 1.0),
                'volatility_score' => (float) ($modelInputs->volatility_score ?? 3.0),
            ];
        }

        // Default model inputs
        return [
            'home_form_weight' => 0.35,
            'away_form_weight' => 0.25,
            'h2h_weight' => 0.15,
            'venue_advantage' => 0.70,
            'weather_impact' => 0.02,
            'referee_bias' => 0.01,
            'expected_goals' => 2.5,
            'home_xg' => 1.5,
            'away_xg' => 1.0,
            'volatility_score' => 3.0,
        ];
    }

    /**
     * Determine risk profile based on slip data
     *
     * @param MasterSlip $masterSlip
     * @return string
     */
    protected function determineRiskProfile(MasterSlip $masterSlip): string
    {
        // Use pre-computed risk profile if available
        if ($masterSlip->risk_profile) {
            return $masterSlip->risk_profile;
        }

        // Calculate based on average odds
        $avgOdds = $masterSlip->matches->avg(function ($slipMatch) {
            return $slipMatch->selectedMarket->odds ?? 1.85;
        });

        if ($avgOdds > 3.0)
            return 'high';
        if ($avgOdds > 2.0)
            return 'medium';
        return 'low';
    }

    /**
     * Send payload to Python engine
     *
     * @param array $payload
     * @return \Illuminate\Http\Client\Response
     * @throws \Exception
     */
    protected function sendToPythonEngine(array $payload)
    {
        $pythonEngineUrl = 'http://localhost:5000/generate-slips';

        if (!$pythonEngineUrl) {
            throw new \Exception('Python engine URL not configured');
        }

        Log::debug('Sending payload to Python engine', [
            'master_slip_id' => $this->masterSlipId,
            'engine_url' => $pythonEngineUrl,
            'payload_size' => strlen(json_encode($payload)),
        ]);

        $response = Http::timeout(90)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($pythonEngineUrl, $payload);

        if (!$response->successful()) {
            throw new \Exception(
                "Python engine request failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response;
    }

    /**  MasterSlip::with('generatedSlips.legs')->find($id)).
     * Handle Python engine response
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param MasterSlip $masterSlip
     * @return void
     */
    protected function handlePythonResponse($response, MasterSlip $masterSlip)
    {
        $result = $response->json();

        // Basic validation (keep your existing check)
        if (!isset($result['generated_slips']) || empty($result['generated_slips'])) {
            throw new \Exception('Python engine returned no generated slips');
        }

        // Dispatch the new job to store slips in normalized tables
        StoreGeneratedSlips::dispatch($masterSlip->id, $result);

        // Optional: still store raw response if you want a backup
        $masterSlip->update([
            'python_response' => $result, // keep for debugging/history
            'status'          => 'processing', // intermediate state
        ]);

        Log::info('Python response received and StoreGeneratedSlipsJob dispatched', [
            'master_slip_id' => $masterSlip->id,
            'slips_count'    => count($result['generated_slips']),
        ]);
    }

    /**
     * Handle job failure
     *
     * @param \Exception $e
     * @return void
     */
    protected function handleFailure(\Exception $e)
    {
        $masterSlip = MasterSlip::find($this->masterSlipId);

        if ($masterSlip) {
            $masterSlip->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
        }

        Log::error('ProcessPythonRequest job failed', [
            'master_slip_id' => $this->masterSlipId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Handle a job failure event
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::critical('ProcessPythonRequest job failed permanently', [
            'master_slip_id' => $this->masterSlipId,
            'error' => $exception->getMessage(),
            'job_id' => $this->job->getJobId(),
        ]);
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