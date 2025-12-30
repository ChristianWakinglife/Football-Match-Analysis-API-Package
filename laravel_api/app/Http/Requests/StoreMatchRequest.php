<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'home_team' => 'required|string|max:255',
            'away_team' => 'required|string|max:255',
            'league' => 'required|string|max:255',
            'competition' => 'nullable|string|max:255',
            'match_date' => 'required|date',
            'match_time' => 'nullable|string|max:10',
            'venue' => 'nullable|string|max:255',

            'home_form' => 'nullable|array',
            'home_form.form_string' => 'nullable|string|max:20',
            'home_form.matches_played' => 'nullable|integer|min:0',
            'home_form.wins' => 'nullable|integer|min:0',
            'home_form.draws' => 'nullable|integer|min:0',
            'home_form.losses' => 'nullable|integer|min:0',
            'home_form.avg_goals_scored' => 'nullable|numeric|min:0',
            'home_form.avg_goals_conceded' => 'nullable|numeric|min:0',
            'home_form.form_rating' => 'nullable|numeric|min:0|max:10',
            'home_form.form_momentum' => 'nullable|numeric|min:-10|max:10',
            'home_form.raw_form' => 'nullable|array',

            'away_form' => 'nullable|array',
            'away_form.form_string' => 'nullable|string|max:20',
            'away_form.matches_played' => 'nullable|integer|min:0',
            'away_form.wins' => 'nullable|integer|min:0',
            'away_form.draws' => 'nullable|integer|min:0',
            'away_form.losses' => 'nullable|integer|min:0',
            'away_form.avg_goals_scored' => 'nullable|numeric|min:0',
            'away_form.avg_goals_conceded' => 'nullable|numeric|min:0',
            'away_form.form_rating' => 'nullable|numeric|min:0|max:10',
            'away_form.form_momentum' => 'nullable|numeric|min:-10|max:10',
            'away_form.raw_form' => 'nullable|array',

            'head_to_head_stats' => 'nullable|array',
            'head_to_head_stats.home_wins' => 'nullable|integer|min:0',
            'head_to_head_stats.away_wins' => 'nullable|integer|min:0',
            'head_to_head_stats.draws' => 'nullable|integer|min:0',
            //last_meetings
            'head_to_head_stats.total_meetings' => 'nullable|integer|min:0',
            'head_to_head_stats.last_meetings' => 'nullable|array',
            'head_to_head_stats.home_goals' => 'nullable|integer|min:0',
            'head_to_head_stats.away_goals' => 'nullable|integer|min:0',
            'head_to_head_stats.avg_goals_per_match' => 'nullable|numeric|min:0',
            'markets' => 'nullable|array',

            // 'odds' => 'nullable|array',

            'weather' => 'nullable|string|max:255',
            'referee' => 'nullable|string|max:255',
            'importance' => 'nullable|string|max:50',
            'tv_coverage' => 'nullable|string|max:255',
            'predicted_attendance' => 'nullable|integer|min:0',

            'for_ml_training' => 'nullable|boolean',
            'prediction_ready' => 'nullable|boolean',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,cancelled',
        ];
    }
}