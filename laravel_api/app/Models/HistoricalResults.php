<?php
// app/Models/HistoricalResult.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoricalResults extends Model
{
    use HasFactory;

    protected $table = 'historical_results';
    protected $fillable = [
        'match_id', 'home_team', 'away_team', 'league',
        'match_date', 'result', 'odds_data', 'venue',
    ];

    protected $casts = [
        'match_date' => 'date',
        'result' => 'array',
        'odds_data' => 'array'
    ];

    // Relationships
    public function match()
    {
        return $this->belongsTo(MatchModel::class);
    }
}