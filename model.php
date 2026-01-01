<?php
// app/Models/OptimizedSlip.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class OptimizedSlip extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'optimized_slips';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'master_slip_id',
        'slip_id',
        'total_odds',
        'confidence_score',
        'risk_category',
        'diversity_score',
        'coverage_role',
        'stake',
        'possible_return',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_odds' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'diversity_score' => 'decimal:2',
        'stake' => 'decimal:2',
        'possible_return' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'id',
        'master_slip_id',
    ];

    /**
     * Get the master slip that owns this optimized slip.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function masterSlip(): BelongsTo
    {
        return $this->belongsTo(MasterSlip::class);
    }

    /**
     * Get the legs for this optimized slip.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function legs(): HasMany
    {
        return $this->hasMany(OptimizedSlipLeg::class);
    }

    /**
     * Scope: Filter by risk category.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRiskCategory($query, string $category)
    {
        return $query->where('risk_category', $category);
    }

    /**
     * Scope: Filter by coverage role.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $role
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCoverageRole($query, string $role)
    {
        return $query->where('coverage_role', $role);
    }

    /**
     * Scope: Filter by minimum confidence score.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $minConfidence
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMinConfidence($query, float $minConfidence)
    {
        return $query->where('confidence_score', '>=', $minConfidence);
    }

    /**
     * Scope: Filter by maximum stake amount.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $maxStake
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMaxStake($query, float $maxStake)
    {
        return $query->where('stake', '<=', $maxStake);
    }

    /**
     * Scope: Order by ROI (Return on Investment).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $direction
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByRoi($query, string $direction = 'desc')
    {
        return $query->orderByRaw('possible_return / stake ' . $direction);
    }

    /**
     * Accessor: Calculate ROI percentage.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function roiPercentage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->stake == 0) {
                    return 0;
                }
                return (($this->possible_return - $this->stake) / $this->stake) * 100;
            }
        );
    }

    /**
     * Accessor: Calculate expected value.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function expectedValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                // EV = (probability * win_amount) - ((1 - probability) * stake)
                $probability = $this->confidence_score;
                $winAmount = $this->possible_return - $this->stake;
                
                return ($probability * $winAmount) - ((1 - $probability) * $this->stake);
            }
        );
    }

    /**
     * Accessor: Get profit amount.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function profit(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->possible_return - $this->stake
        );
    }

    /**
     * Check if this is a high-confidence slip.
     *
     * @return bool
     */
    public function isHighConfidence(): bool
    {
        return $this->confidence_score >= 0.7;
    }

    /**
     * Check if this is a low-risk slip.
     *
     * @return bool
     */
    public function isLowRisk(): bool
    {
        return $this->risk_category === 'Conservative' || $this->risk_category === 'Balanced';
    }

    /**
     * Check if this slip is a hedge.
     *
     * @return bool
     */
    public function isHedge(): bool
    {
        return $this->coverage_role === 'hedge';
    }

    /**
     * Check if this slip is a core selection.
     *
     * @return bool
     */
    public function isCore(): bool
    {
        return $this->coverage_role === 'core';
    }

    /**
     * Get summary statistics for this slip.
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'slip_id' => $this->slip_id,
            'total_odds' => (float) $this->total_odds,
            'confidence' => (float) $this->confidence_score,
            'risk_category' => $this->risk_category,
            'coverage_role' => $this->coverage_role,
            'stake' => (float) $this->stake,
            'possible_return' => (float) $this->possible_return,
            'profit' => (float) $this->profit,
            'roi_percentage' => (float) $this->roi_percentage,
            'expected_value' => (float) $this->expected_value,
            'diversity_score' => (float) $this->diversity_score,
            'is_high_confidence' => $this->isHighConfidence(),
            'is_low_risk' => $this->isLowRisk(),
            'legs_count' => $this->legs()->count(),
        ];
    }
}
