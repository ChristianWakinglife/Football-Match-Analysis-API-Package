<?php

namespace App\Jobs;

use App\Models\MasterSlip;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\Team_Form;
use App\Models\Market;
use App\Models\MarketOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class PrepareSlipDataForML implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Store only the ID for safe serialization
     */
    protected int $masterSlipId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $masterSlipId)
    {
        $this->masterSlipId = $masterSlipId;
    }

    /**
     * Execute the job.
     */
    public function handle(): array
    {
        Log::info('Starting ML match data preparation', [
            'master_slip_id' => $this->masterSlipId
        ]);

        // Load the MasterSlip with matches and their basic relationships
        $masterSlip = MasterSlip::with([
            'matches' => function ($query) {
                $query->with([
                    'homeTeam',
                    'awayTeam',
                    // These might not exist in your schema - adjust accordingly
                    'competition', 
                    'season',
                    'venue',
                    'markets.outcomes'
                ]);
            },
            'riskProfile'
        ])->find($this->masterSlipId);
        
        if (!$masterSlip) {
            Log::error('MasterSlip not found', ['master_slip_id' => $this->masterSlipId]);
            throw new \Exception("MasterSlip {$this->masterSlipId} not found");
        }

        $payload = [
            'master_slip' => [
                'master_slip_id' => $this->formatMasterSlipId($masterSlip),
                'stake' => (float) ($masterSlip->stake ?? 0),
                'currency' => $masterSlip->currency ?? 'EUR',
                'created_at' => $masterSlip->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'total_matches' => $masterSlip->matches->count(),
                'risk_profile' => $masterSlip->riskProfile->name ?? 'medium',
                'matches' => []
            ]
        ];

        foreach ($masterSlip->matches as $match) {
            try {
                $matchData = $this->prepareMatchData($match, $masterSlip);
                $payload['master_slip']['matches'][] = $matchData;
                
                Log::debug('Match data prepared', [
                    'match_id' => $match->id,
                    'home_team' => $match->homeTeam->name ?? 'Unknown',
                    'away_team' => $match->awayTeam->name ?? 'Unknown'
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to prepare match data', [
                    'match_id' => $match->id,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info('ML match data preparation completed', [
            'master_slip_id' => $masterSlip->id,
            'prepared_matches' => count($payload['master_slip']['matches'])
        ]);

        return $payload;
    }

    /**
     * Format master slip ID
     */
    protected function formatMasterSlipId(MasterSlip $masterSlip): string
    {
        if ($masterSlip->custom_id) {
            return $masterSlip->custom_id;
        }
        
        return sprintf(
            'MSL-%s-%03d',
            $masterSlip->created_at?->format('Ymd') ?? date('Ymd'),
            $masterSlip->id % 1000
        );
    }

    /**
     * Format match ID
     */
    protected function formatMatchId(MatchModel $match): string
    {
        if ($match->custom_id) {
            return $match->custom_id;
        }
        
        // Get league abbreviation from team's league or match
        $leagueAbbr = 'UNK';
        if ($match->homeTeam && $match->homeTeam->league) {
            $leagueAbbr = strtoupper(substr($match->homeTeam->league, 0, 3));
        }
        
        return sprintf(
            '%s-%s-%03d',
            $leagueAbbr,
            $match->match_date?->format('Ymd') ?? '00000000',
            $match->id % 1000
        );
    }

    /**
     * Prepare data for a single match
     */
    protected function prepareMatchData(MatchModel $match, MasterSlip $masterSlip): array
    {
        // 1. Match basic info
        $matchData = [
            'match_id' => $this->formatMatchId($match),
            'league' => $match->homeTeam->league ?? 'Unknown League',
            'competition' => $match->competition->name ?? 'Unknown Competition',
            'season' => $match->season->name ?? 'Unknown Season',
            'match_date' => $match->match_date?->format('Y-m-d'),
            'match_time' => $match->match_time?->format('H:i:s'),
            'venue' => $match->venue_type ?? 'home',
            'venue_capacity' => $match->venue?->capacity ?? null,
            'city' => $match->venue?->city ?? $match->homeTeam?->city ?? null,
            'country' => $match->venue?->country ?? $match->homeTeam?->country ?? null,
            'weather' => [
                'temperature' => $match->weather?->temperature ?? 20.0,
                'condition' => $match->weather?->condition ?? 'clear',
                'wind_speed' => $match->weather?->wind_speed ?? 10.0
            ],
            'pitch_type' => $match->venue?->pitch_type ?? 'natural',
            'referee' => $match->referee?->name ?? 'Unknown',
            'home_team' => $match->homeTeam->name ?? 'Unknown',
            'away_team' => $match->awayTeam->name ?? 'Unknown',
        ];

        // 2. Get team forms and ranks from team_forms table
        $homeTeamForm = Team_Form::where('team_id', $match->home_team_id)
            ->where('match_id', $match->id)
            ->first();
            
        $awayTeamForm = Team_Form::where('team_id', $match->away_team_id)
            ->where('match_id', $match->id)
            ->first();

        // 3. Add team ranks and average goals from team forms
        $matchData['home_team_rank'] = $homeTeamForm?->league_position ?? null;
        $matchData['away_team_rank'] = $awayTeamForm?->league_position ?? null;
        $matchData['home_team_avg_goals'] = $homeTeamForm?->avg_goals_scored ?? 0.0;
        $matchData['away_team_avg_goals'] = $awayTeamForm?->avg_goals_scored ?? 0.0;

        // 4. Prepare team form data (cleaned for Python)
        $matchData['home_form'] = $this->prepareTeamFormData($homeTeamForm);
        $matchData['away_form'] = $this->prepareTeamFormData($awayTeamForm);

        // 5. Head-to-head data (you'll need to implement this)
        $matchData['head_to_head'] = $this->prepareHeadToHeadData(
            $match->home_team_id,
            $match->away_team_id
        );

        // 6. Selected market from pivot
        $matchData['selected_market'] = $this->getSelectedMarketData($match, $masterSlip);

        // 7. Full markets
        $matchData['full_markets'] = $this->prepareFullMarketsData($match->markets);

        // 8. Model inputs
        $matchData['model_inputs'] = $this->prepareModelInputs(
            $matchData['home_form'],
            $matchData['away_form'],
            $matchData['head_to_head'],
            $matchData['venue']
        );

        return $matchData;
    }

    /**
     * Clean and prepare team form data for Python
     */
    protected function prepareTeamFormData(?Team_Form $teamForm): array
    {
        if (!$teamForm) {
            return $this->getDefaultFormData();
        }

        // Clean the raw_form - remove unwanted fields and keep only what Python needs
        $cleanedRawForm = [];
        if (!empty($teamForm->raw_form) && is_array($teamForm->raw_form)) {
            foreach ($teamForm->raw_form as $match) {
                $cleanedRawForm[] = [
                    'result' => $match['outcome'] ?? '?',
                    'score' => $match['result'] ?? '0-0',
                    'opponent' => $match['opponent'] ?? 'Unknown'
                ];
            }
        }

        return [
            'form_string' => $teamForm->form_string ?? '',
            'matches_played' => (int) ($teamForm->matches_played ?? 0),
            'wins' => (int) ($teamForm->wins ?? 0),
            'draws' => (int) ($teamForm->draws ?? 0),
            'losses' => (int) ($teamForm->losses ?? 0),
            'avg_goals_scored' => round($teamForm->avg_goals_scored ?? 0, 1),
            'avg_goals_conceded' => round($teamForm->avg_goals_conceded ?? 0, 1),
            'form_rating' => round($teamForm->form_rating ?? 0, 1),
            'form_momentum' => $this->determineFormMomentum($teamForm->form_momentum ?? 0),
            'raw_form' => $cleanedRawForm
        ];
    }

    /**
     * Determine form momentum from numeric value
     */
    protected function determineFormMomentum($momentumValue): string
    {
        if ($momentumValue > 1) return 'positive';
        if ($momentumValue < -1) return 'negative';
        return 'neutral';
    }

    /**
     * Get default form data
     */
    protected function getDefaultFormData(): array
    {
        return [
            'form_string' => '',
            'matches_played' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0,
            'avg_goals_scored' => 0.0,
            'avg_goals_conceded' => 0.0,
            'form_rating' => 0.0,
            'form_momentum' => 'neutral',
            'raw_form' => []
        ];
    }

    /**
     * Prepare head-to-head data (simplified - you need to implement this properly)
     */
    protected function prepareHeadToHeadData(int $homeTeamId, int $awayTeamId): array
    {
        // You'll need to implement this based on your head-to-head data structure
        // This is a simplified version
        return [
            'total_matches' => 0,
            'home_wins' => 0,
            'away_wins' => 0,
            'draws' => 0,
            'avg_goals_per_match' => 0.0,
            'last_5_meetings' => []
        ];
    }

    /**
     * Get selected market data from pivot
     */
    protected function getSelectedMarketData(MatchModel $match, MasterSlip $masterSlip): array
    {
        try {
            $pivotData = $masterSlip->matches()
                ->where('match_id', $match->id)
                ->first()
                ?->pivot;
            
            if (!$pivotData) {
                return $this->getDefaultSelectedMarket();
            }

            $odds = (float) ($pivotData->odds ?? 1.0);
            $impliedProbability = $odds > 0 ? round(1 / $odds, 3) : 1.0;
            
            return [
                'market_type' => $pivotData->market_type ?? '1X2',
                'selection' => $pivotData->selection ?? 'Unknown',
                'odds' => $odds,
                'implied_probability' => $impliedProbability,
                'confidence_rating' => 5.0 // You can calculate this if you have the logic
            ];

        } catch (\Exception $e) {
            Log::warning('Failed to get selected market data', [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);
            
            return $this->getDefaultSelectedMarket();
        }
    }

    /**
     * Default selected market
     */
    protected function getDefaultSelectedMarket(): array
    {
        return [
            'market_type' => '1X2',
            'selection' => 'Unknown',
            'odds' => 1.0,
            'implied_probability' => 1.0,
            'confidence_rating' => 5.0
        ];
    }

    /**
     * Prepare full markets data
     */
    protected function prepareFullMarketsData(Collection $markets): array
    {
        $fullMarkets = [];
        
        foreach ($markets as $market) {
            $marketOptions = [];
            
            foreach ($market->outcomes as $outcome) {
                $odds = (float) ($outcome->odds ?? 1.0);
                $impliedProbability = $odds > 0 ? round(1 / $odds, 3) : 1.0;
                
                // Format based on market name
                $marketName = strtolower($market->name ?? '');
                
                if (str_contains($marketName, 'correct') || str_contains($marketName, 'score')) {
                    $marketOptions[] = [
                        'score' => $outcome->name,
                        'odds' => $odds,
                        'implied_probability' => $impliedProbability
                    ];
                } elseif (str_contains($marketName, 'asian') || str_contains($marketName, 'handicap')) {
                    $marketOptions[] = [
                        'handicap' => $outcome->name,
                        'odds' => $odds,
                        'implied_probability' => $impliedProbability
                    ];
                } elseif (str_contains($marketName, 'over') || str_contains($marketName, 'under')) {
                    $marketOptions[] = [
                        'line' => $outcome->name,
                        'odds' => $odds,
                        'implied_probability' => $impliedProbability
                    ];
                } elseif (str_contains($marketName, 'corner')) {
                    $marketOptions[] = [
                        'type' => $outcome->type ?? 'total',
                        'line' => $outcome->line ?? '0.0',
                        'odds' => $odds,
                        'implied_probability' => $impliedProbability
                    ];
                } else {
                    $marketOptions[] = [
                        'selection' => $outcome->name,
                        'odds' => $odds,
                        'implied_probability' => $impliedProbability
                    ];
                }
            }
            
            $fullMarkets[] = [
                'market_name' => $market->name ?? 'unknown_market',
                'options' => $marketOptions
            ];
        }
        
        return $fullMarkets;
    }

    /**
     * Prepare model inputs
     */
    protected function prepareModelInputs(
        array $homeForm,
        array $awayForm,
        array $headToHead,
        string $venue
    ): array {
        // Simple calculations - adjust based on your actual model
        $homeFormRating = $homeForm['form_rating'] ?? 0;
        $awayFormRating = $awayForm['form_rating'] ?? 0;
        
        return [
            'home_form_weight' => round($homeFormRating / 10, 2),
            'away_form_weight' => round($awayFormRating / 10, 2),
            'h2h_weight' => round($headToHead['total_matches'] > 0 ? 0.2 : 0.1, 2),
            'venue_advantage' => $venue === 'home' ? 0.85 : ($venue === 'away' ? 0.15 : 0.5),
            'weather_impact' => 0.01,
            'referee_bias' => 0.02,
            'expected_goals' => round(
                ($homeForm['avg_goals_scored'] ?? 0) + ($awayForm['avg_goals_scored'] ?? 0), 
                1
            ),
            'home_xg' => round($homeForm['avg_goals_scored'] ?? 0, 1),
            'away_xg' => round($awayForm['avg_goals_scored'] ?? 0, 1),
            'volatility_score' => 2.5
        ];
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('PrepareMLMatchDataJob failed', [
            'master_slip_id' => $this->masterSlipId,
            'error' => $exception->getMessage()
        ]);
    }
}