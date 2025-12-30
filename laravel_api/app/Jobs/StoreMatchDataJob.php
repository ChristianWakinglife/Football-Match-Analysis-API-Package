<?php

namespace App\Jobs;

use App\DTO\MatchPayloadDTO;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\Team_Form;
use App\Models\Head_To_Head;
use App\Models\Market;
use App\Models\MatchMarket;
use App\Models\MatchMarketOutcome;
use App\Services\TeamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreMatchDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $retryAfter = 30;

    /**
     * The match payload DTO
     */
    protected MatchPayloadDTO $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(MatchPayloadDTO $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(TeamService $teamService): array
    {
        Log::info('Starting StoreMatchDataJob', [
            'home_team' => $this->payload->homeTeam,
            'away_team' => $this->payload->awayTeam,
            'league' => $this->payload->league,
            'has_h2h_data' => $this->payload->hasHeadToHeadData(),
            'h2h_data_keys' => $this->payload->hasHeadToHeadData() ? array_keys($this->payload->headToHeadStats) : 'none'
        ]);

        set_time_limit(60);
        $start = microtime(true);

        DB::beginTransaction();

        try {
            // 1. Resolve teams using the service
            $homeTeam = $teamService->resolveTeam($this->payload->homeTeam, $this->payload->league);
            $awayTeam = $teamService->resolveTeam($this->payload->awayTeam, $this->payload->league);

            $teamResolveTime = microtime(true);
            Log::info('Teams resolved', [
                'time' => $teamResolveTime - $start,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id
            ]);

            // 2. Create match
            $match = $this->createMatchRecord($homeTeam, $awayTeam);

            $createTime = microtime(true);
            Log::info('Match created', [
                'time' => $createTime - $teamResolveTime,
                'match_id' => $match->id
            ]);

            // 3. Store team forms if provided
            if ($this->payload->hasFormData()) {
                $this->storeTeamForms($match, $homeTeam, $awayTeam);
                $formsTime = microtime(true);
                Log::info('Forms stored', ['time' => $formsTime - $createTime]);
            }

            // 4. Store head-to-head if provided
            if ($this->payload->hasHeadToHeadData()) {
                Log::info('Attempting to store head-to-head data', [
                    'match_id' => $match->id,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id
                ]);

                $h2hResult = $this->storeHeadToHead($match, $homeTeam->id, $awayTeam->id, $this->payload->headToHeadStats);

                $h2hTime = microtime(true);
                Log::info('H2H stored', [
                    'time' => $h2hTime - ($formsTime ?? $createTime),
                    'success' => $h2hResult !== null,
                    'h2h_id' => $h2hResult?->id ?? 'failed'
                ]);
            } else {
                Log::info('No head-to-head data provided in payload');
            }

            // 5. Store markets if provided
            if ($this->payload->hasMarketsData()) {
                $this->storeMarkets($match->id);
                $marketsTime = microtime(true);
                Log::info('Markets stored', ['time' => $marketsTime - ($h2hTime ?? $formsTime ?? $createTime)]);
            }

            DB::commit();

            $totalTime = microtime(true) - $start;
            Log::info('StoreMatchDataJob completed successfully', [
                'match_id' => $match->id,
                'total_time' => round($totalTime, 2),
                'h2h_saved' => isset($h2hResult) && $h2hResult !== null
            ]);

            return [
                'success' => true,
                'match_id' => $match->id,
                'match' => $match->fresh(['homeTeam', 'awayTeam', 'teamForms', 'headToHead']),
                'timing' => [
                    'total_seconds' => round($totalTime, 2),
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('StoreMatchDataJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $this->getSanitizedPayload(),
            ]);

            // Re-throw the exception so the job can retry
            throw $e;
        }
    }

    /**
     * Create match record
     */
    protected function createMatchRecord(Team $homeTeam, Team $awayTeam): MatchModel
    {
        return MatchModel::create([
            'home_team' => $this->payload->homeTeam,
            'away_team' => $this->payload->awayTeam,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'league' => trim($this->payload->league),
            'competition' => $this->payload->competition ?? trim($this->payload->league),
            'match_date' => $this->payload->getCombinedDateTime(),
            'venue' => $this->payload->venue,
            'weather' => $this->payload->weather,
            'referee' => $this->payload->referee,
            'importance' => $this->payload->importance,
            'tv_coverage' => $this->payload->tvCoverage,
            'predicted_attendance' => $this->payload->predictedAttendance,
            'for_ml_training' => $this->payload->forMlTraining,
            'prediction_ready' => $this->payload->predictionReady,
            'analysis_status' => 'pending',
            'status' => $this->payload->status,
            'home_score' => $this->payload->homeScore,
            'away_score' => $this->payload->awayScore,
        ]);
    }

    /**
     * Store team forms
     */
    protected function storeTeamForms(MatchModel $match, Team $homeTeam, Team $awayTeam): void
    {
        // Store home team form
        if ($this->payload->homeForm) {
            Team_Form::updateOrCreate(
                [
                    'match_id' => $match->id,
                    'team_id' => $homeTeam->id,
                    'venue' => 'home',
                ],
                $this->prepareTeamFormData($this->payload->homeForm, $homeTeam->id, 'home')
            );
        }

        // Store away team form
        if ($this->payload->awayForm) {
            Team_Form::updateOrCreate(
                [
                    'match_id' => $match->id,
                    'team_id' => $awayTeam->id,
                    'venue' => 'away',
                ],
                $this->prepareTeamFormData($this->payload->awayForm, $awayTeam->id, 'away')
            );
        }
    }

    /**
     * Prepare team form data for storage
     */
    protected function prepareTeamFormData(array $formData, int $teamId, string $venue): array
    {
        return [
            'team_id' => $teamId,
            'venue' => $venue,
            'form_string' => $formData['form_string'] ?? '',
            'matches_played' => (int) ($formData['matches_played'] ?? 0),
            'wins' => (int) ($formData['wins'] ?? 0),
            'draws' => (int) ($formData['draws'] ?? 0),
            'losses' => (int) ($formData['losses'] ?? 0),
            'goals_scored' => (int) ($formData['goals_scored'] ?? 0),
            'goals_conceded' => (int) ($formData['goals_conceded'] ?? 0),
            'avg_goals_scored' => (float) ($formData['avg_goals_scored'] ?? 0),
            'avg_goals_conceded' => (float) ($formData['avg_goals_conceded'] ?? 0),
            'clean_sheets' => (int) ($formData['clean_sheets'] ?? 0),
            'failed_to_score' => (int) ($formData['failed_to_score'] ?? 0),
            'form_rating' => (float) ($formData['form_rating'] ?? 5.0),
            'form_momentum' => (float) ($formData['form_momentum'] ?? 0.0),
            'raw_form' => $formData['raw_form'] ?? [],
            'calculated_at' => now(),
        ];
    }

    /**
     * Store head-to-head data
     */
    protected function storeHeadToHead(MatchModel $match, int $homeTeamId, int $awayTeamId, array $headToHeadStats): ?Head_To_Head
    {
        try {
            $matchId = $match->id;

            // Calculate total goals from last meetings
            $totalHomeGoals = 0;
            $totalAwayGoals = 0;

            if (isset($headToHeadStats['last_meetings']) && is_array($headToHeadStats['last_meetings'])) {
                foreach ($headToHeadStats['last_meetings'] as $meeting) {
                    if (isset($meeting['score'])) {
                        list($homeScore, $awayScore) = explode('-', $meeting['score']);
                        $totalHomeGoals += (int) $homeScore;
                        $totalAwayGoals += (int) $awayScore;
                    }
                }
            }

            // Prepare data for database
            $data = [
                'match_id' => $matchId,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
                'home_wins' => (int) ($headToHeadStats['home_wins'] ?? 0),
                'away_wins' => (int) ($headToHeadStats['away_wins'] ?? 0),
                'draws' => (int) ($headToHeadStats['draws'] ?? 0),
                'total_meetings' => (int) ($headToHeadStats['total_meetings'] ?? 0),
                'home_goals' => $totalHomeGoals,
                'away_goals' => $totalAwayGoals,
            ];

            // Calculate average goals per match
            if ($data['total_meetings'] > 0) {
                $data['avg_goals_per_match'] = ($totalHomeGoals + $totalAwayGoals) / $data['total_meetings'];
            }

            // Store last meetings WITHOUT json_encode (let Laravel handle the JSON casting)
            // Laravel will automatically JSON encode arrays if the column is cast as array/json
            $data['last_meetings'] = $headToHeadStats['last_meetings'] ?? [];

            // Save to database
            $headToHead = Head_To_Head::updateOrCreate(
                [
                    'match_id' => $matchId,
                    'home_team_id' => $homeTeamId,
                    'away_team_id' => $awayTeamId
                ],
                $data
            );

            Log::info('Head-to-head saved successfully', [
                'id' => $headToHead->id,
                'match_id' => $matchId,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId
            ]);

            return $headToHead;

        } catch (\Exception $e) {
            Log::error('Failed to save head-to-head', [
                'match_id' => $matchId ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Store markets
     */
    protected function storeMarkets(int $matchId): void
    {
        DB::transaction(function () use ($matchId) {
            foreach ($this->payload->markets as $marketData) {
                // Generate market code
                $marketCode = $this->generateMarketCode($marketData['name']);

                // Create or find market
                $market = Market::firstOrCreate(
                    [
                        'name' => $marketData['name'],
                        'market_type' => $marketData['market_type'],
                    ],
                    [
                        'code' => $marketCode,
                        'description' => ucfirst(str_replace('_', ' ', $marketData['name'] ?? '')),
                        'is_active' => true,
                        'sort_order' => $this->getNextSortOrder(),
                    ]
                );

                // Prepare market_data WITHOUT json_encode
                $marketDataArray = [
                    'source' => 'manual',
                    'raw_name' => $marketData['name'],
                ];

                // Attach market to match
                $matchMarket = MatchMarket::updateOrCreate(
                    [
                        'match_id' => $matchId,
                        'market_id' => $market->id,
                    ],
                    [
                        'odds' => $marketData['odds'] ?? 0,
                        'market_data' => $marketDataArray, // Direct array, no json_encode
                        'is_active' => true,
                    ]
                );

                // Store outcomes
                if (!empty($marketData['outcomes']) && is_array($marketData['outcomes'])) {
                    $this->storeMarketOutcomes($matchMarket->id, $marketData['outcomes']);
                }
            }
        });
    }

    /**
     * Store market outcomes
     */
    protected function storeMarketOutcomes(int $matchMarketId, array $outcomes): void
    {
        foreach ($outcomes as $index => $outcomeData) {
            MatchMarketOutcome::updateOrCreate(
                [
                    'match_market_id' => $matchMarketId,
                    'outcome' => $outcomeData['outcome'],
                ],
                [
                    'label' => $this->generateOutcomeLabel($outcomeData['outcome']),
                    'odds' => $outcomeData['odds'] ?? 0,
                    'sort_order' => $index + 1,
                    'is_default' => $index === 0,
                ]
            );
        }
    }

    /**
     * Generate a market code from market name
     */
    protected function generateMarketCode(string $marketName): string
    {
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $marketName));

        if (strlen($code) > 50) {
            $code = substr($code, 0, 50);
        }

        return $code;
    }

    /**
     * Get the next sort order for markets
     */
    protected function getNextSortOrder(): int
    {
        $max = Market::max('sort_order');
        return $max ? $max + 1 : 1;
    }

    /**
     * Generate a human-readable outcome label
     */
    protected function generateOutcomeLabel(string $outcomeName): string
    {
        $labels = [
            'win' => 'Win',
            'lose' => 'Lose',
            'draw' => 'Draw',
            'over' => 'Over',
            'under' => 'Under',
            'yes' => 'Yes',
            'no' => 'No',
            'home' => 'Home Win',
            'away' => 'Away Win',
            'both_teams_score' => 'Both Teams Score'
        ];

        return $labels[$outcomeName] ?? ucfirst(str_replace('_', ' ', $outcomeName));
    }

    /**
     * Get sanitized payload for logging
     */
    protected function getSanitizedPayload(): array
    {
        $payload = $this->payload->toArray();

        // Sanitize large arrays
        if (isset($payload['home_form']['raw_form'])) {
            $payload['home_form']['raw_form'] = ['truncated' => true];
        }

        if (isset($payload['away_form']['raw_form'])) {
            $payload['away_form']['raw_form'] = ['truncated' => true];
        }

        if (isset($payload['head_to_head_stats']['last_meetings'])) {
            $payload['head_to_head_stats']['last_meetings'] = ['truncated' => true];
        }

        if (isset($payload['markets'])) {
            $payload['markets'] = ['count' => count($this->payload->markets)];
        }

        return $payload;
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('StoreMatchDataJob failed permanently', [
            'error' => $exception->getMessage(),
            'payload' => $this->getSanitizedPayload(),
            'attempts' => $this->attempts(),
        ]);
    }
}