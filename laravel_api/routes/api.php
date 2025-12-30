<?php

use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TeamFormController;
use App\Http\Controllers\Api\Head_To_Head_Controller;
use App\Http\Controllers\Api\SlipController;
use App\Http\Controllers\Api\GeneratorController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\MasterSlipController;
use App\Http\Controllers\Api\PythonCallbackController;





// routes/api.php
use App\Http\Controllers\Api\PredictionController;

Route::get('/test-queue', function () {
    \App\Jobs\GenerateAlternativeSlipsJob::dispatch(6, 'default', 'medium');
    return 'Dispatched';
});

Route::get('/test-markets', function () {

$match = \App\Models\MatchModel::with('matchMarkets.outcomes')->find(40); // your latest match ID
dd($match->matchMarkets->pluck('outcomes'));
});

Route::post('/matches/try-payload', [MatchController::class, 'generateEngineSlips']);


Route::post('/matches/{id}/generate-predictions', [MatchController::class, 'generatePredictions']);
Route::get('/predictions/{match_id}', [PredictionController::class, 'getByMatch']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//health returns ok
Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});
//return api is working
Route::get('/test', function (Request $request) {
    return response()->json(['message' => 'API is working'], 200);
});

// Polygot   return response()->json($request->all());

// match
Route::prefix('matches')->group(function () {
    Route::get('/', [MatchController::class, 'index']);
    Route::post('/', [MatchController::class, 'storeMatchData']);
    Route::get('/{id}', [MatchController::class, 'show']);
    Route::put('/{id}', [MatchController::class, 'update']);
    Route::delete('/{id}', [MatchController::class, 'destroy']);
    Route::get('/for-ml', [MatchController::class, 'getMatchesForML']);
    Route::post('/{id}/prediction', [MatchController::class, 'updatePrediction']);
    Route::get('/search', [MatchController::class, 'searchTeams']);

    Route::get('/{id}/stats', [MatchController::class, 'stats']);
});
    // getMatchesForBetslip
Route::get('/getMatches-betslip', [MatchController::class, 'getMatchesAllForBetslip']);
Route::get('/single-betslip/{id}', [MatchController::class, 'getMatchForBetslip']);

Route::post('/matches/{id}/generate-predictions', [MatchController::class, 'generatePredictions'])
    ->name('matches.generate-predictions');


Route::prefix('teams')->group(function () {
    Route::get('/', [TeamController::class, 'index']);
    Route::post('/', [TeamController::class, 'store']);
    Route::post('/bulk-import', [TeamController::class, 'bulkImport']);
    Route::get('/search', [TeamController::class, 'search']);
    Route::get('/best-form', [TeamController::class, 'bestForm']);
    Route::get('/{code}', [TeamController::class, 'show']);
    Route::put('/{code}', [TeamController::class, 'update']);
    Route::get('/{code}/stats', [TeamController::class, 'getStats']);
    Route::post('/{code}/update-stats', [TeamController::class, 'updateMatchStats']);
    // No delete? Add if needed: Route::delete('/{code}', [TeamController::class, 'destroy']);
});

    // Team Focrms (RESTful)
// Route::apiResource('team-forms', TeamFormController::class)->except(['show', 'destroy']); // No show/destroy in frontend
Route::post('team-forms/calculate', [TeamFormController::class, 'calculate']);




/********************************************************************************************************************* *

 API update
 Frontend calls POST /api/generator with master slip and options

Laravel creates GeneratorJob record and dispatches ProcessPythonRequest

Python service processes and calls POST /api/generator/webhook/{jobId}

Laravel updates job status and creates generated slips

Frontend polls GET /api/generator/status/{jobId} for updates

/********************************************************************************************************************* */


Route::post('generator/{jobId}/webhook', [GeneratorController::class, 'updateJobStatus']);
Route::post('generator/webhook/{jobId}', [GeneratorController::class, 'webhook'])
    ->name('api.generator.webhook');


// Health check (simple, no controller needed if basic)
Route::get('/health', function () {
    return response()->json(['status' => 'healthy']);
});


// Head-to-Head
Route::group(['prefix' => 'head-to-head'], function () {
    Route::post('/', [Head_To_Head_Controller::class, 'store']);
    Route::get('/{matchId}', [Head_To_Head_Controller::class, 'show']);
    Route::put('/{matchId}', [Head_To_Head_Controller::class, 'update']);
    Route::get('/calculate', [Head_To_Head_Controller::class, 'calculate']); // home_team, away_team params
});

Route::post('/master-slips', [MasterSlipController::class, 'store']);
// Route::apiResource('slips', SlipController::class);
Route::post('slips/master', [SlipController::class, 'createMaster']);
Route::post('slips/{slipId}/matches', [SlipController::class, 'addMatch']);

// Generator
Route::post('generator', [GeneratorController::class, 'generate']);
Route::get('generator/status/{jobId}', [GeneratorController::class, 'status']);
Route::get('generator/jobs', [GeneratorController::class, 'listJobs']);
Route::post('generator/{jobId}/cancel', [GeneratorController::class, 'cancel']);


Route::get('markets/{marketId}/outcomes', [MarketController::class, 'outcomes']);




    // Betslip endpoint
// Slip Management Routes
Route::prefix('slips')->group(function () {

    // Get all master slips (optional endpoint)
    Route::get('/', [SlipController::class, 'getAllMasterSlips']);

    // Get generated slips for a specific master slip
    Route::get('/{masterSlipId}/generated-slips', [SlipController::class, 'getGeneratedSlips'])
        ->where('masterSlipId', '[0-9A-Za-z\-]+'); // Allows UUIDs and numeric IDs

    // Get detailed view of a single generated slip
    Route::get('/generated/{generatedSlipId}', [SlipController::class, 'getSlipDetail'])
        ->where('generatedSlipId', '[0-9A-Za-z\-]+');

    // Delete a master slip and all related data
    Route::delete('/{masterSlipId}', [SlipController::class, 'deleteSlip'])
        ->where('masterSlipId', '[0-9A-Za-z\-]+');

    // Get slip status (useful for polling)
    Route::get('/{masterSlipId}/status', [SlipController::class, 'getSlipStatus'])
        ->where('masterSlipId', '[0-9A-Za-z\-]+');

    // Get slip statistics for dashboards
    Route::get('/statistics', [SlipController::class, 'getSlipsStatistics']);

    // Export slips to CSV
    Route::get('/export/csv', [SlipController::class, 'exportSlipsToCSV']);

    // Get recent slips (optional endpoint)
    Route::get('/recent', [SlipController::class, 'getRecentSlips']);
});


// Alternative route naming for frontend compatibility
Route::prefix('master-slips')->group(function () {
    Route::get('/{masterSlipId}/slips', [SlipController::class, 'getGeneratedSlips']);
    Route::get('/{masterSlipId}/slips-stats', [SlipController::class, 'getSlipsStatistics']);
    Route::get('/{masterSlipId}/export-slips', [SlipController::class, 'exportSlipsToCSV']);
});

// Direct slip routes for convenience
Route::get('/slip/{generatedSlipId}', [SlipController::class, 'getSlipDetail']);

Route::post('/slips/create', [SlipController::class, 'createSlip']);
Route::post('/slips/{slip}/add-match', [SlipController::class, 'addMatchesToSlip']);
Route::delete('/slips/{slip}/remove-match/{matchId}', [SlipController::class, 'removeMatchFromSlip']);
Route::get('/all-master-slips', [SlipController::class, 'getMasterSlips']);
Route::get('/master-slips/{id}', [SlipController::class, 'DagetMasterSlip']);

Route::get('/da-master-slips/{id}', [SlipController::class, 'DagetMasterSlipDetail']);
Route::get('/slips/{id}/matches', [SlipController::class, 'getSlipMatches']);
Route::get('/slips/{id}/generated', [SlipController::class, 'DagetGeneratedSlips']);
Route::post('/slips/{id}/analyze', [SlipController::class, 'analyzeSlip']);
Route::put('/single-slips/{id}', [SlipController::class, 'updateMasterSlip']); // For editing
Route::get('/slips/{id}/dashboard', [SlipController::class, 'getDashboardSlip']);
// get active slip
Route::get('/slips/active-master-slips', [SlipController::class, 'showActiveSlip']);
//addMatchToMasterSlip
Route::post('/slips/{id}/add-match-old-method', [SlipController::class, 'addMatchToMasterSlip']);

Route::get('/slips/{id}/insights', [SlipController::class, 'getSlipInsights']);
Route::post('/slips/{id}/run-analysis', [SlipController::class, 'runSlipAnalysis']);
Route::get('/slips/{id}/status', [SlipController::class, 'checkAnalysisStatus']);

Route::post('/slips/{id}/add-match', [MasterslipController::class, 'addMatchToMasterSlip']);


    // Market endpoints
 Route::get('markets', [MarketController::class, 'index']);
    
    // Job/analysis endpoints
Route::prefix('jobs')->group(function () {
    Route::post('analyze-betslip', [JobController::class, 'analyzeBetslip']);
    Route::get('{jobId}/status', [JobController::class, 'getStatus']);
    Route::get('{jobId}/results', [JobController::class, 'getResults']);
});
    
    // Optional: Statistics endpoint
    Route::get('stats/matches', [\App\Http\Controllers\Api\StatsController::class, 'matches']);

Route::get('prepareMatchData/{masterSlipId}', [MasterSlipController::class, 'prepareMatchDataForML']);
//get team form
Route::get('/team-form/{teamId}', [MasterSlipController::class, 'teamFormData']);
// insertTemporaryForm
Route::get('/teams-data/insert/{id}', [MasterSlipController::class, 'insertTemporaryForm']);

// python response

Route::post('/python-callback/{masterSlipId}', [PythonCallbackController::class, 'handleCallback'])
    ->where('masterSlipId', '[0-9]+')
    ->name('python.callback');