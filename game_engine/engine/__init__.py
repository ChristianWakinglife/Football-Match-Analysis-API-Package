# game_engine/engine/__init__.py

"""
Football Analysis Engine Package
Contains all core engines for match analysis and slip generation.
"""

from .probability_engine import ProbabilityEngine
from .match_simulator import MatchSimulator
from .confidence_scorer import ConfidenceScorer
# from .slip_builder import SlipBuilder  # REMOVED: _ContextInferencer
from .insight_engine import MatchInsightEngine
from .monte_carlo import MonteCarloSimulator
from .coverage import CoverageOptimizer
from .scoring import ScoringEngine  

from .slip_builder import (
    SlipBuilder,
    MarketExtractor,
    HedgingEngine,
    SlipVariationGenerator,
    PortfolioOptimizer
)

# Version of the engine package
__version__ = "3.0.0"  # Updated version

# Export the main classes
__all__ = [
    "ProbabilityEngine",
    "MatchSimulator", 
    "ConfidenceScorer",
    "SlipBuilder",
    # REMOVED: "_ContextInferencer",
    "MatchInsightEngine",
    "MonteCarloSimulator",
    # "CoverageOptimizer",
    "ScoringEngine",
    'MarketExtractor',
    'HedgingEngine',
    'SlipVariationGenerator',
    'PortfolioOptimizer',
    # 'CoverageAnalyzer'
]

# Package metadata
__author__ = "Football Analysis Team"
__description__ = "Core intelligence engines for football match predictions"