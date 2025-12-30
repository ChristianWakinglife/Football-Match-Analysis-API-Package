<?php
// app/Models/HeadToHead.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Head_To_Head extends Model
{
    use HasFactory;

    protected $table = 'head_to_head';
    protected $fillable = [
        'match_id',
        'home_team_id',   // Add this
        'away_team_id',   // Add this
        'home_wins',
        'away_wins',
        'draws',
        'total_meetings',
        'last_meetings',  // Changed from last_meetings to match JSON
        'home_goals',
        'away_goals',
        'avg_goals_per_match'  // Add this for Python engine
    ];

    protected $casts = [
        'last_meetings' => 'array',
        'home_wins' => 'integer',
        'away_wins' => 'integer',
        'draws' => 'integer',
        'total_meetings' => 'integer',
        'home_goals' => 'integer',
        'away_goals' => 'integer',
        'avg_goals_per_match' => 'float'
    ];

    // Relationships
    public function match()
    {
        return $this->belongsTo(MatchModel::class, 'match_id');
    }

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    // Calculate average goals per match
    public function getAvgGoalsPerMatchAttribute()
    {
        if ($this->total_meetings === 0)
            return 0;
        return ($this->home_goals + $this->away_goals) / $this->total_meetings;
    }

    // For Python engine compatibility
    public function getHeadToHeadArrayAttribute()
    {
        return [
            'total_matches' => $this->total_meetings,
            'home_wins' => $this->home_wins,
            'away_wins' => $this->away_wins,
            'draws' => $this->draws,
            'avg_goals_per_match' => $this->getAvgGoalsPerMatchAttribute(),
            'last_5_meetings' => $this->last_meetings ?? []
        ];
    }

    // Accessor for easy "2-1-2" format
    public function getFormAttribute($value)
    {
        if ($value) {
            return $value;
        }
        
        // Generate from structured data if form not set
        return $this->home_wins . '-' . $this->draws . '-' . $this->away_wins;
    }

    // Mutator to parse "2-1-2" format
    public function setFormAttribute($value)
    {
        $this->attributes['form'] = $value;
        
        // Parse "2-1-2" format into structured data
        if ($value && preg_match('/^(\d+)-(\d+)-(\d+)$/', $value, $matches)) {
            $this->attributes['home_wins'] = (int)$matches[1];
            $this->attributes['draws'] = (int)$matches[2];
            $this->attributes['away_wins'] = (int)$matches[3];
            $this->attributes['total_meetings'] = (int)$matches[1] + (int)$matches[2] + (int)$matches[3];
        }
    }

    // Calculate win percentages
    public function getHomeWinPercentageAttribute()
    {
        if ($this->total_meetings === 0) return 0;
        return ($this->home_wins / $this->total_meetings) * 100;
    }

    public function getAwayWinPercentageAttribute()
    {
        if ($this->total_meetings === 0) return 0;
        return ($this->away_wins / $this->total_meetings) * 100;
    }

    public function getDrawPercentageAttribute()
    {
        if ($this->total_meetings === 0) return 0;
        return ($this->draws / $this->total_meetings) * 100;
    }

    // Get last meeting details
    public function getLastMeetingAttribute()
    {
        if ($this->last_meetings && is_array($this->last_meetings) && count($this->last_meetings) > 0) {
            return $this->last_meetings[0]; // Most recent meeting
        }
        return null;
    }

    // Get form as array [home_wins, draws, away_wins]
    public function getFormArrayAttribute()
    {
        return [$this->home_wins, $this->draws, $this->away_wins];
    }

    // Get form as readable string
    public function getFormReadableAttribute()
    {
        return sprintf("%d Home Wins, %d Draws, %d Away Wins", 
            $this->home_wins, $this->draws, $this->away_wins);
    }

    // Check if home team is dominant
    public function getIsHomeDominantAttribute()
    {   
        if ($this->total_meetings === 0) return false;
        return $this->home_wins > $this->away_wins;
    }

    // Check if away team is dominant
    public function getIsAwayDominantAttribute()
    {
        if ($this->total_meetings === 0) return false;
        return $this->away_wins > $this->home_wins;
    }
}