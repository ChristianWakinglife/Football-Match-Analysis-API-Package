<?php
// config/python.php

return [
    /*
    |--------------------------------------------------------------------------
    | Python Generator Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Python ML generator service.
    |
    */

    'service' => [
        // Python service URL (FastAPI/Flask) - Changed to port 5000
        'url' => env('PYTHON_SERVICE_URL', 'http://localhost:5000'),

        // Timeout in seconds
        'timeout' => env('PYTHON_SERVICE_TIMEOUT', 60),

        // Retry attempts
        'retry_attempts' => env('PYTHON_SERVICE_RETRIES', 3),

        // Retry delay in milliseconds
        'retry_delay' => env('PYTHON_SERVICE_RETRY_DELAY', 1000),
    ],

    'endpoints' => [
        // Match analysis endpoints
        'analyze_match' => '/api/analyze-match',
        'analyze_batch' => '/api/analyze-batch',

        // Team form analysis
        'analyze_team_form' => '/api/analyze-team-form',
        'calculate_form_stats' => '/api/calculate-form-stats',

        // Head-to-head analysis
        'analyze_head_to_head' => '/api/analyze-head-to-head',
        'calculate_h2h_stats' => '/api/calculate-h2h-stats',

        // Prediction generation
        'generate_predictions' => '/api/generate-predictions',
        'generate_slips' => '/api/generate-slips',

        // ML model endpoints
        'train_model' => '/api/train-model',
        'predict' => '/api/predict',

        // Health check
        'health' => '/api/health',
    ],

    'ml' => [
        // Model configurations
        'models' => [
            'form_analysis' => env('PYTHON_MODEL_FORM_ANALYSIS', 'ensemble_form_v1.pkl'),
            'h2h_analysis' => env('PYTHON_MODEL_H2H_ANALYSIS', 'ensemble_h2h_v1.pkl'),
            'prediction' => env('PYTHON_MODEL_PREDICTION', 'neural_network_v2.pkl'),
        ],

        // Feature configurations
        'features' => [
            'form_features' => ['form_rating', 'form_momentum', 'avg_goals_scored', 'avg_goals_conceded', 'win_probability'],
            'h2h_features' => ['home_win_percentage', 'away_win_percentage', 'goal_supremacy', 'total_meetings'],
            'team_features' => ['overall_rating', 'attack_rating', 'defense_rating', 'home_strength', 'away_strength'],
        ],

        // Prediction thresholds
        'thresholds' => [
            'confidence_high' => 0.7,
            'confidence_medium' => 0.55,
            'confidence_low' => 0.45,
            'form_advantage_significant' => 1.0,
            'goal_supremacy_significant' => 0.5,
        ],
    ],

    'cache' => [
        // Cache TTL in seconds
        'match_analysis' => env('PYTHON_CACHE_MATCH_TTL', 3600), // 1 hour
        'team_form' => env('PYTHON_CACHE_TEAM_FORM_TTL', 1800), // 30 minutes
        'h2h_analysis' => env('PYTHON_CACHE_H2H_TTL', 7200), // 2 hours
        'predictions' => env('PYTHON_CACHE_PREDICTIONS_TTL', 1800), // 30 minutes
        'slip_generation' => env('PYTHON_CACHE_SLIP_TTL', 300), // 5 minutes
    ],

    'queues' => [
        // Queue configuration - Updated with multiple queues for different job types
        'default_queue' => env('PYTHON_QUEUE_DEFAULT', 'python-default'),
        'ml_processing' => env('PYTHON_QUEUE_ML', 'ml-processing'),
        'slip_generation' => env('PYTHON_QUEUE_SLIP', 'slip-generation'),
        'batch_size' => env('PYTHON_BATCH_SIZE', 50),
        'max_retries' => env('PYTHON_MAX_RETRIES', 3),
        'retry_delay' => env('PYTHON_RETRY_DELAY', 60), // seconds
    ],

    'monitoring' => [
        // Monitoring and logging
        'enable_logging' => env('PYTHON_ENABLE_LOGGING', true),
        'log_level' => env('PYTHON_LOG_LEVEL', 'info'),
        'enable_metrics' => env('PYTHON_ENABLE_METRICS', false),
        'metrics_endpoint' => env('PYTHON_METRICS_ENDPOINT', '/api/metrics'),
    ],

    // Job specific configurations
    'jobs' => [
        'process_python_request' => [
            'timeout' => 60, // seconds
            'tries' => 3,
            'backoff' => [5, 10, 15], // seconds between retries
        ],
        'process_match_for_ml' => [
            'timeout' => 120, // seconds
            'tries' => 3,
            'backoff' => [10, 30, 60], // seconds between retries
        ],
        'generate_slips' => [
            'timeout' => 300, // 5 minutes
            'tries' => 2,
            'backoff' => [30, 60], // seconds between retries
        ],
    ],

    // Slip generation configuration
    'slips' => [
        'strategies' => ['monte_carlo', 'coverage', 'ml_prediction'],
        'risk_profiles' => ['conservative', 'balanced', 'aggressive'],
        'default_options' => [
            'max_slips' => 100,
            'strategies' => ['monte_carlo', 'coverage', 'ml_prediction'],
            'risk_profile' => 'balanced',
            'min_odds' => 1.5,
            'max_odds' => 10.0,
            'bankroll_percentage' => 5,
            'stake_per_slip' => 1.0,
            'max_slips_to_store' => 50,
        ],
    ],

    // Form analysis configuration
    'form' => [
        'recent_matches_limit' => 5,
        'form_string_length' => 5,
        'rating_calculation' => [
            'win_weight' => 10,
            'draw_weight' => 5,
            'loss_weight' => 0,
            'recent_weight' => 1.5,
            'normal_weight' => 1.0,
        ],
    ],
];