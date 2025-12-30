<?php
// app/Models/Team.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'teams';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'short_name',
        'code',
        //'slug',
        'country',
        'overall_rating',
        // 'attack_rating',
        // 'defense_rating',
        // 'home_strength',
        // 'away_strength',
        'matches_played',
        'wins',
        'draws',
        'losses',
        'goals_scored',
        'goals_conceded',
        'goal_difference',
        'points',
        'league_position',
        'current_form',
        'form_rating',
        'momentum',
        // 'expected_goals_for',
        // 'expected_goals_against',
        // 'possession_avg',
        // 'shots_on_target_avg',
        // 'clean_sheet_percentage',
        // 'conversion_rate',
        // 'home_stats',
        // 'away_stats',
        // 'recent_performances',
        // 'is_top_team',
        // 'is_bottom_team',
        // 'has_home_advantage',
        // 'is_improving',
        'fair_odds_home_win',
        'fair_odds_draw',
        'fair_odds_away_win'
    ];

    protected $casts = [
        'overall_rating' => 'float',
        'attack_rating' => 'float',
        'defense_rating' => 'float',
        'home_strength' => 'float',
        'away_strength' => 'float',
        'form_rating' => 'float',
        'momentum' => 'float',
        // 'expected_goals_for' => 'float',
        // 'expected_goals_against' => 'float',
        // 'possession_avg' => 'float',
        // 'shots_on_target_avg' => 'float',
        // 'clean_sheet_percentage' => 'float',
        // 'conversion_rate' => 'float',
        // 'home_stats' => 'array',
        // 'away_stats' => 'array',
        // 'recent_performances' => 'array',
        // 'is_top_team' => 'boolean',
        // 'is_bottom_team' => 'boolean',
        // 'has_home_advantage' => 'boolean',
        // 'is_improving' => 'boolean',
        'fair_odds_home_win' => 'float',
        'fair_odds_draw' => 'float',
        'fair_odds_away_win' => 'float',
        'last_updated' => 'datetime'
    ];

    protected $appends = [
        'win_percentage',
        'draw_percentage',
        'loss_percentage',
        'avg_goals_scored',
        'avg_goals_conceded',
        'strength_category'
    ];

    // Relationships

    public function homeMatches(): HasMany
    {
        return $this->hasMany(\App\Models\MatchModel::class, 'home_team_id');
    }

    public function awayMatches(): HasMany
    {
        return $this->hasMany(\App\Models\MatchModel::class, 'away_team_id');
    }
    public function teamForms()
    {
        return $this->hasMany(Team_Form::class, 'team_id', 'code');
    }

        /**
     * Proper unified matches query
     */
    public function allMatches()
    {
        return \App\Models\MatchModel::query()
            ->where('home_team_id', $this->id)
            ->orWhere('away_team_id', $this->id);
    }

    // Accessors
    public function getWinPercentageAttribute()
    {
        if ($this->matches_played === 0)
            return 0;
        return round(($this->wins / $this->matches_played) * 100, 1);
    }

    public function getDrawPercentageAttribute()
    {
        if ($this->matches_played === 0)
            return 0;
        return round(($this->draws / $this->matches_played) * 100, 1);
    }

    public function getLossPercentageAttribute()
    {
        if ($this->matches_played === 0)
            return 0;
        return round(($this->losses / $this->matches_played) * 100, 1);
    }

    public function getAvgGoalsScoredAttribute()
    {
        if ($this->matches_played === 0)
            return 0;
        return round($this->goals_scored / $this->matches_played, 2);
    }

    public function getAvgGoalsConcededAttribute()
    {
        if ($this->matches_played === 0)
            return 0;
        return round($this->goals_conceded / $this->matches_played, 2);
    }

    public function getStrengthCategoryAttribute()
    {
        if ($this->overall_rating >= 8.0)
            return 'elite';
        if ($this->overall_rating >= 7.0)
            return 'strong';
        if ($this->overall_rating >= 6.0)
            return 'average';
        if ($this->overall_rating >= 5.0)
            return 'weak';
        return 'poor';
    }

    public function getFormAnalysisAttribute()
    {
        $form = $this->current_form ?? '';
        $wins = substr_count($form, 'W');
        $draws = substr_count($form, 'D');
        $losses = substr_count($form, 'L');
        $total = strlen($form);

        if ($total === 0)
            return null;

        return [
            'wins' => $wins,
            'draws' => $draws,
            'losses' => $losses,
            'total' => $total,
            'form_points' => ($wins * 3) + $draws,
            'avg_points_per_match' => round((($wins * 3) + $draws) / $total, 2)
        ];
    }

    // Methods
    public function updateStatistics(array $matchResult): void
    {
        // Update basic stats
        $this->matches_played++;

        $result = $matchResult['result'] ?? ''; // 'W', 'D', 'L'
        $goalsScored = $matchResult['goals_scored'] ?? 0;
        $goalsConceded = $matchResult['goals_conceded'] ?? 0;

        switch ($result) {
            case 'W':
                $this->wins++;
                $this->points += 3;
                break;
            case 'D':
                $this->draws++;
                $this->points += 1;
                break;
            case 'L':
                $this->losses++;
                break;
        }

        $this->goals_scored += $goalsScored;
        $this->goals_conceded += $goalsConceded;
        $this->goal_difference = $this->goals_scored - $this->goals_conceded;

        // Update form string (keep last 5 matches)
        $formChar = $result ?: '?';
        $currentForm = $this->current_form ?? '';
        $newForm = substr($currentForm . $formChar, -5);
        $this->current_form = $newForm;

        // Update form rating
        $this->form_rating = $this->calculateFormRating();

        // Update momentum
        $this->momentum = $this->calculateMomentum();

        // Update strength indicators
        $this->updateStrengthIndicators();

        $this->last_updated = now();
        $this->save();
    }

    private function calculateFormRating(): float
    {
        $form = $this->current_form ?? '';
        if (strlen($form) === 0)
            return 5.0;

        $rating = 0;
        $weights = [1.2, 1.1, 1.0, 0.9, 0.8]; // Weight recent matches more

        for ($i = 0; $i < min(strlen($form), 5); $i++) {
            $char = $form[$i];
            $weight = $weights[$i] ?? 1.0;

            switch ($char) {
                case 'W':
                    $rating += 10 * $weight;
                    break;
                case 'D':
                    $rating += 5 * $weight;
                    break;
                case 'L':
                    $rating += 0 * $weight;
                    break;
                default:
                    $rating += 5 * $weight; // Neutral
            }
        }

        $totalWeight = array_sum(array_slice($weights, 0, strlen($form)));
        return round($rating / $totalWeight, 2);
    }

    private function calculateMomentum(): float
    {
        $form = $this->current_form ?? '';
        if (strlen($form) < 3)
            return 0;

        $recent = substr($form, -3);
        $previous = strlen($form) >= 6 ? substr($form, -6, 3) : '';

        if ($previous === '')
            return 0;

        $recentPoints = $this->calculateFormPoints($recent);
        $previousPoints = $this->calculateFormPoints($previous);

        $maxPoints = 9; // 3 wins
        $momentum = ($recentPoints - $previousPoints) / $maxPoints;

        return round(max(-1, min(1, $momentum)), 2);
    }

    private function calculateFormPoints(string $form): int
    {
        $points = 0;
        for ($i = 0; $i < strlen($form); $i++) {
            switch ($form[$i]) {
                case 'W':
                    $points += 3;
                    break;
                case 'D':
                    $points += 1;
                    break;
                case 'L':
                    $points += 0;
                    break;
            }
        }
        return $points;
    }

    private function updateStrengthIndicators(): void
    {
        // Update is_top_team flag (top 20% by rating)
        $this->is_top_team = $this->overall_rating >= 7.5;

        // Update is_bottom_team flag (bottom 20% by rating)
        $this->is_bottom_team = $this->overall_rating <= 4.5;

        // Update has_home_advantage flag
        $this->has_home_advantage = $this->home_strength > $this->away_strength + 0.5;

        // Update is_improving flag
        $this->is_improving = $this->momentum > 0.3;
    }

    /**
     * Get data formatted for Python ML model
     */
    public function toMLFormat(): array
    {
        return [
            'team_id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'ratings' => [
                'overall' => $this->overall_rating,
                'attack' => $this->attack_rating,
                'defense' => $this->defense_rating,
                'home' => $this->home_strength,
                'away' => $this->away_strength,
                'form' => $this->form_rating
            ],
            'stats' => [
                'matches_played' => $this->matches_played,
                'wins' => $this->wins,
                'draws' => $this->draws,
                'losses' => $this->losses,
                'goals_scored' => $this->goals_scored,
                'goals_conceded' => $this->goals_conceded,
                'goal_difference' => $this->goal_difference,
                'points' => $this->points
            ],
            'averages' => [
                'goals_scored' => $this->avg_goals_scored,
                'goals_conceded' => $this->avg_goals_conceded,
                'possession' => $this->possession_avg,
                'shots_on_target' => $this->shots_on_target_avg
            ],
            'form' => [
                'current_form' => $this->current_form,
                'momentum' => $this->momentum,
                'analysis' => $this->form_analysis
            ],
            'indicators' => [
                'is_top_team' => $this->is_top_team,
                'is_bottom_team' => $this->is_bottom_team,
                'has_home_advantage' => $this->has_home_advantage,
                'is_improving' => $this->is_improving,
                'strength_category' => $this->strength_category
            ],
            'fair_odds' => [
                'home_win' => $this->fair_odds_home_win,
                'draw' => $this->fair_odds_draw,
                'away_win' => $this->fair_odds_away_win
            ]
        ];
    }

    /**
     * Calculate fair odds based on team statistics
     */
    public function calculateFairOdds(Team $opponent, bool $isHome = true): array
    {
        $homeStrength = $isHome ? $this->home_strength : $this->away_strength;
        $awayStrength = $isHome ? $opponent->away_strength : $opponent->home_strength;

        // Base probability calculation
        $totalStrength = $homeStrength + $awayStrength;
        $homeWinProb = $homeStrength / $totalStrength * 0.45; // Adjusted for home advantage
        $awayWinProb = $awayStrength / $totalStrength * 0.35;
        $drawProb = 0.20; // Base draw probability

        // Adjust based on form
        $formAdjustment = ($this->form_rating - $opponent->form_rating) / 20;
        $homeWinProb += $formAdjustment;
        $awayWinProb -= $formAdjustment;

        // Normalize probabilities
        $sum = $homeWinProb + $awayWinProb + $drawProb;
        $homeWinProb /= $sum;
        $awayWinProb /= $sum;
        $drawProb /= $sum;

        // Convert to odds (European/decimal format)
        return [
            'home_win' => round(1 / $homeWinProb, 2),
            'draw' => round(1 / $drawProb, 2),
            'away_win' => round(1 / $awayWinProb, 2),
            'probabilities' => [
                'home' => round($homeWinProb, 4),
                'draw' => round($drawProb, 4),
                'away' => round($awayWinProb, 4)
            ]
        ];
    }

    //temporary
    public function leaguePosition()
    {
        return random_int(1, 18);
    }

    // Scopes
    public function scopeTopTeams($query, int $limit = 10)
    {
        return $query->where('is_top_team', true)
            ->orderBy('overall_rating', 'desc')
            ->limit($limit);
    }

    public function scopeWithGoodForm($query, float $threshold = 6.5)
    {
        return $query->where('form_rating', '>=', $threshold)
            ->orderBy('form_rating', 'desc');
    }

    public function scopeImproving($query, float $momentumThreshold = 0.2)
    {
        return $query->where('momentum', '>=', $momentumThreshold)
            ->orderBy('momentum', 'desc');
    }

    public function scopeByCountry($query, string $country)
    {
        return $query->where('country', $country);
    }

    //   $teams->getCollection()->transform(function ($team) {
    //             $team->additional_stats = $this->getAdditionalTeamStats($team);
    //             return $team;
    //         });

    public function getCollection(){
        return $this->newQuery();
    }
    /**
     * Get head-to-head comparison with another team
     */
    public function compareWith(Team $opponent): array
    {
        return [
            'team_a' => $this->name,
            'team_b' => $opponent->name,
            'rating_comparison' => [
                'overall' => $this->overall_rating - $opponent->overall_rating,
                'attack' => $this->attack_rating - $opponent->attack_rating,
                'defense' => $this->defense_rating - $opponent->defense_rating
            ],
            'form_comparison' => [
                'form_rating' => $this->form_rating - $opponent->form_rating,
                'momentum' => $this->momentum - $opponent->momentum,
                'current_form' => $this->current_form . ' vs ' . $opponent->current_form
            ],
            'goal_comparison' => [
                'scored' => $this->avg_goals_scored - $opponent->avg_goals_scored,
                'conceded' => $this->avg_goals_conceded - $opponent->avg_goals_conceded,
                'difference' => $this->goal_difference - $opponent->goal_difference
            ],
            'strength_indicators' => [
                'team_a_top' => $this->is_top_team,
                'team_b_top' => $opponent->is_top_team,
                'team_a_improving' => $this->is_improving,
                'team_b_improving' => $opponent->is_improving
            ]
        ];
    }
}