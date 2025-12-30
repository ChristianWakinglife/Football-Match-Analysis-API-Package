# game_engine/schemas.py

from pydantic import BaseModel, Field, validator
from typing import List, Dict, Any, Optional, Union
from decimal import Decimal
import re

class FlexibleIDMixin:
    """Mixin to handle flexible ID types (string or integer)"""
    
    @validator('match_id', 'master_slip_id', 'original_master_slip_id', 'team_id', 
               'slip_id', 'match_id', pre=True, check_fields=False)
    def convert_to_string(cls, v):
        """Convert any ID value to string"""
        if v is None:
            return ""
        return str(v)

class SlipLeg(BaseModel, FlexibleIDMixin):
    match_id: str = Field(..., description="Match ID as string")
    market: str
    selection: str
    odds: float
    is_fallback: bool = False
    
    class Config:
        arbitrary_types_allowed = True

class GeneratedSlip(BaseModel, FlexibleIDMixin):
    slip_id: str = Field(..., description="Slip ID as string")
    legs: List[SlipLeg]
    total_odds: float
    confidence_score: float
    stake: float = Field(default=0.0)
    possible_return: float = Field(default=0.0)
    risk_level: str = Field(default="Unknown Risk")
    error: Optional[str] = None
    variation_type: Optional[str] = None
    edge_score: Optional[float] = Field(default=0.0)

class MatchData(BaseModel, FlexibleIDMixin):
    """Flexible match data model accepting both string and integer IDs"""
    match_id: str
    home_team: str
    away_team: str
    venue: str = "Neutral"
    home_team_id: Optional[str] = None
    away_team_id: Optional[str] = None
    selected_market: Optional[Dict[str, Any]] = None
    full_markets: List[Dict[str, Any]] = Field(default_factory=list)
    team_form: Optional[Dict[str, Any]] = None
    head_to_head: Optional[Dict[str, Any]] = None
    model_inputs: Optional[Dict[str, Any]] = None
    probabilities: Optional[Dict[str, float]] = None
    
    @validator('match_id', 'home_team_id', 'away_team_id', pre=True)
    def normalize_ids(cls, v):
        if v is None:
            return ""
        return str(v)

class MasterSlipData(BaseModel, FlexibleIDMixin):
    """Flexible master slip data model"""
    master_slip_id: str
    original_master_slip_id: Optional[str] = None
    stake: float = Field(ge=0.0)
    currency: str = "EUR"
    matches: List[MatchData]
    
    @validator('master_slip_id', 'original_master_slip_id', pre=True)
    def normalize_slip_ids(cls, v):
        if v is None:
            return ""
        return str(v)

class MasterSlipRequest(BaseModel):
    master_slip: MasterSlipData

class EngineResponse(BaseModel, FlexibleIDMixin):
    master_slip_id: str
    generated_slips: List[GeneratedSlip]
    metadata: Optional[Dict[str, Any]] = Field(default_factory=dict)
    
    @validator('master_slip_id', pre=True)
    def normalize_master_slip_id(cls, v):
        if v is None:
            return ""
        return str(v)