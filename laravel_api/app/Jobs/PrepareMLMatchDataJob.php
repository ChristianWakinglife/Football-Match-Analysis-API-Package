<?php

namespace App\Jobs;

use App\Models\MasterSlip;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\Team_Form;
use App\Models\Head_To_Head;
use App\Models\Market;
use App\Models\MatchMarket;
use App\Models\MatchMarketOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class PrepareMLMatchDataJob implements ShouldQueue
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

        // Load the MasterSlip with all necessary relationships
        $masterSlip = $this->loadMasterSlipWithRelationships();
        
        if (!$masterSlip) {
            Log::error('MasterSlip not found', ['master_slip_id' => $this->masterSlipId]);
            throw new \Exception("MasterSlip {$this->masterSlipId} not found");
        }

        Log::info('MasterSlip loaded successfully', [
            'master_slip_id' => $masterSlip->id,
            'match_count' => $masterSlip->matches->count()
        ]);

        // Build the exact payload structure for Python
        $payload = [
            'master_slip' => [
                'master_slip_id' => $this->masterSlipId,
                'stake' => (float) ($masterSlip->stake ?? 0),
                'currency' => $masterSlip->currency ?? 'EUR',
                'created_at' => $masterSlip->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'total_matches' => $masterSlip->matches->count(),
                'risk_profile' => 'medium',
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
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Continue with other matches instead of failing completely
                continue;
            }
        }

        Log::info('ML match data preparation completed', [
            'master_slip_id' => $masterSlip->id,
            'prepared_matches' => count($payload['master_slip']['matches']),
            'total_matches' => $masterSlip->matches->count()
        ]);

        return $payload;
    }

    /**
     * Load MasterSlip with all necessary relationships
     * Based on your actual database structure
     */
    protected function loadMasterSlipWithRelationships(): ?MasterSlip
    {
        return MasterSlip::with([
            'matches' => function ($query) {
                $query->with([
                    'homeTeam',
                    'awayTeam',
                    // Load team forms for this match
                    'teamForms' => function ($query) {
                        $query->whereIn('venue', ['home', 'away']);
                    },
                    // Load head-to-head for this match
                    'headToHead',
                    // Load markets with outcomes
                    'matchMarkets.market.outcomes',
                ]);
            },
        ])->find($this->masterSlipId);
    }

    /**
     * Format master slip ID according to the expected pattern
     */
    protected function formatMasterSlipId(MasterSlip $masterSlip): string
    {
        if ($masterSlip->custom_id) {
            return $masterSlip->custom_id;
        }
        
        // Format: MSL-YYYYMMDD-XXX
        return sprintf(
            'MSL-%s-%03d',
            $masterSlip->created_at?->format('Ymd') ?? date('Ymd'),
            $masterSlip->id % 1000
        );
    }

    /**
     * Format match ID according to the expected pattern
     */
    protected function formatMatchId(MatchModel $match): string
    {
        if ($match->custom_id) {
            return $match->custom_id;
        }
        
        // Get league abbreviation from match league or team league
        $league = $match->league ?? $match->homeTeam->league ?? 'UNK';
        $leagueCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $league), 0, 3));
        if (empty($leagueCode)) {
            $leagueCode = 'UNK';
        }
        
        $date = $match->match_date?->format('Ymd') ?? '00000000';
        
        return sprintf(
            '%s-%s-%03d',
            $leagueCode,
            $date,
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
            'league' => $match->league ?? 'Unknown League',
            'competition' => $match->competition ?? $match->league ?? 'Unknown Competition',
            'season' => '2023-2024', // You'll need to get this from your data
            'match_date' => $match->match_date?->format('Y-m-d'),
            'match_time' => $match->match_time?->format('H:i:s'),
            'venue' => $match->venue ?? 'home',
            'venue_capacity' => null, // Add if you have this data
            'city' => $match->homeTeam?->city ?? null,
            'country' => $match->homeTeam?->country ?? null,
            'weather' => [
                'temperature' => 20.0,
                'condition' => 'clear',
                'wind_speed' => 10.0
            ],
            'pitch_type' => 'natural', // Add if you have this data
            'referee' => $match->referee ?? 'Unknown',
            'home_team' => $match->homeTeam?->name ?? 'Unknown',
            'away_team' => $match->awayTeam?->name ?? 'Unknown',
        ];

        // 2. Get team forms from team_forms table
        $homeTeamForm = $match->teamForms->where('team_id', $match->home_team_id)
            ->where('venue', 'home')
            ->first();
            
        $awayTeamForm = $match->teamForms->where('team_id', $match->away_team_id)
            ->where('venue', 'away')
            ->first();

        // 3. Add team ranks and average goals from team forms
        $matchData['home_team_rank'] = $homeTeamForm?->league_position ?? null;
        $matchData['away_team_rank'] = $awayTeamForm?->league_position ?? null;
        $matchData['home_team_avg_goals'] = $homeTeamForm?->avg_goals_scored ?? 0.0;
        $matchData['away_team_avg_goals'] = $awayTeamForm?->avg_goals_scored ?? 0.0;

        // 4. Prepare team form data (cleaned for Python)
        $matchData['home_form'] = $this->prepareTeamFormData($homeTeamForm);
        $matchData['away_form'] = $this->prepareTeamFormData($awayTeamForm);

        // 5. Head-to-head data
        $matchData['head_to_head'] = $this->prepareHeadToHeadData($match);

        // 6. Selected market from pivot
        $matchData['selected_market'] = $this->prepareSelectedMarketData($match, $masterSlip);

        // 7. Full markets
        $matchData['full_markets'] = $this->prepareFullMarketsData($match);

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

        // Clean the raw_form
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
     * Prepare head-to-head data from Head_To_Head model
     */
    protected function prepareHeadToHeadData(MatchModel $match): array
    {
        $headToHead = $match->headToHead;

        if (!$headToHead) {
            return $this->getDefaultHeadToHeadData();
        }

        // Format last meetings for Python
        $lastMeetings = [];
        if (!empty($headToHead->last_meetings) && is_array($headToHead->last_meetings)) {
            foreach ($headToHead->last_meetings as $meeting) {
                $lastMeetings[] = [
                    'date' => $meeting['date'] ?? '',
                    'score' => $meeting['score'] ?? '0-0',
                    'venue' => $meeting['home_team'] ?? 'Unknown',
                    'winner' => $this->determineWinner($meeting['result'] ?? '', $meeting['home_team'] ?? '', $meeting['away_team'] ?? '')
                ];
            }
        }

        return [
            'total_matches' => (int) $headToHead->total_meetings,
            'home_wins' => (int) $headToHead->home_wins,
            'away_wins' => (int) $headToHead->away_wins,
            'draws' => (int) $headToHead->draws,
            'avg_goals_per_match' => round($headToHead->avg_goals_per_match ?? 0, 1),
            'last_5_meetings' => array_slice($lastMeetings, 0, 5)
        ];
    }

    /**
     * Determine winner from result code
     */
    protected function determineWinner($result, $homeTeam, $awayTeam): string
    {
        if ($result === 'H' || $result === 'home') return $homeTeam;
        if ($result === 'A' || $result === 'away') return $awayTeam;
        if ($result === 'D' || $result === 'draw') return 'Draw';
        return 'Unknown';
    }

    /**
     * Get default head-to-head data
     */
    protected function getDefaultHeadToHeadData(): array
    {
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
     * Prepare selected market data from pivot
     */
    protected function prepareSelectedMarketData(MatchModel $match, MasterSlip $masterSlip): array
    {
        $defaultMarket = [
            'market_type' => '1X2',
            'selection' => 'Unknown',
            'odds' => 1.0,
            'implied_probability' => 1.0,
            'confidence_rating' => 0.0
        ];

        try {
            // Get pivot data from master slip matches relationship
            $pivotData = $masterSlip->matches()
                ->where('match_id', $match->id)
                ->first()
                ?->pivot;
            
            if (!$pivotData) {
                return $defaultMarket;
            }

            $odds = (float) ($pivotData->odds ?? 1.0);
            $impliedProbability = $odds > 0 ? round(1 / $odds, 3) : 1.0;
            
            return [
                'market_type' => $pivotData->market_type ?? '1X2',
                'selection' => $pivotData->selection ?? 'Unknown',
                'odds' => $odds,
                'implied_probability' => $impliedProbability,
                'confidence_rating' => 5.0 // Default confidence
            ];

        } catch (\Exception $e) {
            Log::warning('Failed to prepare selected market data', [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);
            
            return $defaultMarket;
        }
    }

    /**
     * Prepare full markets data
     */
    protected function prepareFullMarketsData(MatchModel $match): array
    {
        $fullMarkets = [];
        
        // Get markets through matchMarkets relationship
        foreach ($match->matchMarkets as $matchMarket) {
            $market = $matchMarket->market;
            if (!$market) {
                continue;
            }

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
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}