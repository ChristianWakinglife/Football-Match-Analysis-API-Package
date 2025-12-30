<?php

// App/Services/HeadToHeadService.php
namespace App\Services;

use App\Models\Head_To_Head;

class HeadToHeadService
{
    public function getHeadToHeadData(int $matchId, int $homeTeamId, int $awayTeamId)
    {
        return Head_To_Head::where('match_id', $matchId)
            ->where('home_team_id', $homeTeamId)
            ->where('away_team_id', $awayTeamId)
            ->first();
    }
}


// // App/Services/TeamFormService.php
// namespace App\Services;

// use App\Models\Team_Form;

// class TeamFormService
// {
//     public function getFormData(int $teamId, int $matchId)
//     {
//         return Team_Form::where('team_id', $teamId)
//             ->where('match_id', $matchId)
//             ->first();
//     }
// }