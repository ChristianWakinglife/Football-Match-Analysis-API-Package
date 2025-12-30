<?php

namespace App\DTO;
//add storeMatchRequest
use App\Http\Requests\StoreMatchRequest;

class MatchPayloadDTO
{
    public function __construct(
        public string $homeTeam,
        public string $awayTeam,
        public string $league,
        public string $matchDate,
        public ?string $matchTime = null,
        public ?string $venue = null,
        public ?string $referee = null,
        public ?string $weather = null,
        public ?string $status = 'scheduled',
        public ?int $homeScore = null,
        public ?int $awayScore = null,
        public ?string $importance = null,
        public ?string $competition = null,
        public ?string $tvCoverage = null,
        public ?int $predictedAttendance = 0,
        public ?bool $forMlTraining = true,
        public ?bool $predictionReady = false,
        
        // Form data
        public ?array $homeForm = null,
        public ?array $awayForm = null,
        
        // Head-to-head data
        public ?array $headToHeadStats = null,
        
        // Markets data
        public ?array $markets = null,
    ) {
        // Set competition to league if not provided
        $this->competition = $competition ?? $league;
        $this->matchTime = $matchTime ?? '00:00:00';
    }

    /**
     * Create DTO from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            homeTeam: $data['home_team'],
            awayTeam: $data['away_team'],
            league: $data['league'],
            matchDate: $data['match_date'],
            matchTime: $data['match_time'] ?? null,
            venue: $data['venue'] ?? null,
            referee: $data['referee'] ?? null,
            weather: $data['weather'] ?? null,
            status: $data['status'] ?? 'scheduled',
            homeScore: $data['home_score'] ?? null,
            awayScore: $data['away_score'] ?? null,
            importance: $data['importance'] ?? null,
            competition: $data['competition'] ?? null,
            tvCoverage: $data['tv_coverage'] ?? null,
            predictedAttendance: $data['predicted_attendance'] ?? 0,
            forMlTraining: $data['for_ml_training'] ?? true,
            predictionReady: $data['prediction_ready'] ?? false,
            homeForm: $data['home_form'] ?? null,
            awayForm: $data['away_form'] ?? null,
            headToHeadStats: $data['head_to_head_stats'] ?? null,
            markets: $data['markets'] ?? null,
        );
    }

    /**
     * Create DTO from request
     */
    public static function fromRequest(StoreMatchRequest $request): self
    {
        return self::fromArray($request->validated());
    }

    /**
     * Convert to array for storage
     */
    public function toArray(): array
    {
        return [
            'home_team' => $this->homeTeam,
            'away_team' => $this->awayTeam,
            'league' => $this->league,
            'match_date' => $this->matchDate,
            'match_time' => $this->matchTime,
            'venue' => $this->venue,
            'referee' => $this->referee,
            'weather' => $this->weather,
            'status' => $this->status,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
            'importance' => $this->importance,
            'competition' => $this->competition,
            'tv_coverage' => $this->tvCoverage,
            'predicted_attendance' => $this->predictedAttendance,
            'for_ml_training' => $this->forMlTraining,
            'prediction_ready' => $this->predictionReady,
            'home_form' => $this->homeForm,
            'away_form' => $this->awayForm,
            'head_to_head_stats' => $this->headToHeadStats,
            'markets' => $this->markets,
        ];
    }

    /**
     * Validate required fields
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->homeTeam)) {
            $errors[] = 'Home team is required';
        }

        if (empty($this->awayTeam)) {
            $errors[] = 'Away team is required';
        }

        if (empty($this->league)) {
            $errors[] = 'League is required';
        }

        if (empty($this->matchDate)) {
            $errors[] = 'Match date is required';
        }

        return $errors;
    }

    /**
     * Check if DTO has form data
     */
    public function hasFormData(): bool
    {
        return !empty($this->homeForm) || !empty($this->awayForm);
    }

    /**
     * Check if DTO has head-to-head data
     */
    public function hasHeadToHeadData(): bool
    {
        return !empty($this->headToHeadStats);
    }

    /**
     * Check if DTO has markets data
     */
    public function hasMarketsData(): bool
    {
        return !empty($this->markets) && is_array($this->markets);
    }

    /**
     * Get combined datetime
     */
    public function getCombinedDateTime(): string
    {
        return $this->matchDate . ' ' . $this->matchTime;
    }
}