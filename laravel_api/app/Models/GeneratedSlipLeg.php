<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedSlipLeg extends Model
{
    protected $fillable = [
        'generated_slip_id',
        'match_id',
        'market',
        'selection',
        'odds',
    ];

    public function generatedSlip(): BelongsTo
    {
        return $this->belongsTo(GeneratedSlip::class, 'generated_slip_id');
    }

    //belongs to match

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchModel::class, 'match_id');
    }
}