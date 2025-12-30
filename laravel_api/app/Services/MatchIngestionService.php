<?php

namespace App\Services;

use App\DTO\MatchPayloadDTO;
use App\Jobs\StoreMatchDataJob;
use Illuminate\Support\Facades\Log;

class MatchIngestionService
{
    protected TeamService $teamService;

    public function __construct(TeamService $teamService)
    {
        $this->teamService = $teamService;
    }

    /**
     * Store match data synchronously (immediate processing)
     */
    public function storeMatchData(array $validatedData): array
    {
        $dto = MatchPayloadDTO::fromArray($validatedData);

        // Run job immediately (sync)
        $job = new StoreMatchDataJob($dto);
        return $job->handle($this->teamService);
    }

    /**
     * Queue match data for asynchronous processing
     */
    public function queueMatchData(array $validatedData): void
    {
        $dto = MatchPayloadDTO::fromArray($validatedData);

        StoreMatchDataJob::dispatch($dto)
            ->onQueue('match-ingestion')
            ->delay(now()->addSeconds(5)); // Optional delay

        Log::info('Match data queued for async processing', [
            'home_team' => $dto->homeTeam,
            'away_team' => $dto->awayTeam,
            'league' => $dto->league,
        ]);
    }

    /**
     * Validate and create DTO from raw data
     */
    public function validateAndCreateDTO(array $data): MatchPayloadDTO
    {
        $dto = MatchPayloadDTO::fromArray($data);

        $errors = $dto->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid match payload: ' . implode(', ', $errors));
        }

        return $dto;
    }
}