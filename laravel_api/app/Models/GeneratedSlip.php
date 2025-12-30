<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;




class GeneratedSlip extends Model
{
    protected $fillable = [
        'master_slip_id',
        'slip_id',
        'stake',
        'total_odds',
        'possible_return',
        'risk_level',
        'confidence_score',
        'raw_data',
        'variation_type', // New field
        'edge_score',    // New field
        'error',         // New field

    ];

    protected $casts = [
        'raw_data' => 'array',
        'stake' => 'decimal:2',
        'total_odds' => 'decimal:2',
        'possible_return' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'edge_score' => 'decimal:4',

    ];

    public function masterSlip(): BelongsTo
    {
        return $this->belongsTo(MasterSlip::class, 'master_slip_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(GeneratedSlipLeg::class, 'generated_slip_id');
    }
}