# game_engine/app.py

import uvicorn
import os
import logging
import time  
from typing import Dict, Any, Optional
from contextlib import asynccontextmanager
from logging.handlers import RotatingFileHandler
from fastapi import FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
import httpx # You will need to 
from fastapi import BackgroundTasks

# --- UPDATED IMPORTS ---
from .schemas import MasterSlipRequest, EngineResponse
from .engine.slip_builder import SlipBuilder # Now handles its own internal engine wiring
from .engine.insight_engine import MatchInsightEngine

# --- LOGGING CONFIGURATION ---
def setup_logging():
    log_dir = "logs"
    os.makedirs(log_dir, exist_ok=True)
    engine_logger = logging.getLogger("engine_logger")
    engine_logger.setLevel(logging.INFO)
    
    file_handler = RotatingFileHandler(
        os.path.join(log_dir, "engine.log"), 
        maxBytes=10*1024*1024, 
        backupCount=5
    )
    file_handler.setFormatter(logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s'))
    engine_logger.addHandler(file_handler)
    return engine_logger

logger = setup_logging()

# --- LIFECYCLE MANAGEMENT ---
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Initialize the "Brain" on startup
    # SlipBuilder now internally manages MarketExtractor and HedgingEngine
    app.state.slip_builder = SlipBuilder()
    app.state.insight_engine = MatchInsightEngine()
    logger.info("Analytical Engines Initialized and Ready")
    yield
    # Cleanup logic here if needed
    logger.info("Shutting down engine services")

app = FastAPI(
    title="Freedom Train AI - Game Engine",
    version="3.1.0",
    lifespan=lifespan
)

# --- MIDDLEWARE ---
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- ROUTES ---

async def post_to_callback(callback_url: str, data: Dict[str, Any]):
    """Background task to send results back to Laravel"""
    print(f"\n📡 Attempting to send callback to: {callback_url}")
    print(f"📦 Payload contains {len(data.get('generated_slips', []))} slips")
    
    async with httpx.AsyncClient() as client:
        try:
            # Ensure master_slip_id is integer in final payload
            if "master_slip_id" in data:
                data["master_slip_id"] = int(data["master_slip_id"])
            
            print(f"🔄 Sending POST request...")
            response = await client.post(callback_url, json=data, timeout=10.0)
            print(f"✅ Callback sent successfully! Status: {response.status_code}")
            
            if response.status_code != 200:
                print(f"⚠️  Warning: Received status code {response.status_code}")
                print(f"Response body: {response.text[:200]}...")
            
        except httpx.TimeoutException:
            print(f"⏰ Timeout: Could not reach callback URL within 10 seconds")
            logger.error(f"Timeout reaching callback URL {callback_url}")
        except Exception as e:
            print(f"❌ Callback failed: {e}")
            logger.error(f"Failed to reach callback URL {callback_url}: {e}")

@app.post("/generate-slips")
async def generate_slips(payload: MasterSlipRequest, background_tasks: BackgroundTasks):
    # 1. Use hardcoded callback URL instead of extracting from payload
    callback_url = "http://localhost:8000/api/python-callback"
    
    print(f"\n🔗 Using hardcoded callback URL: {callback_url}")
    
    # 2. Extract master slip ID from payload for the callback URL
    ms_id_raw = payload.master_slip.master_slip_id
    try:
        clean_id = int(ms_id_raw)
    except:
        clean_id = 0
    
    # 3. Append master slip ID to callback URL
    full_callback_url = f"{callback_url}/{clean_id}"
    print(f"📞 Full callback URL: {full_callback_url}")
    
    # 4. Extract and pre-calculate integer ID for background task
    try:
        ms_id_raw = payload.master_slip.master_slip_id
        clean_id = int(ms_id_raw)
    except:
        clean_id = 0

    print(f"🔄 Starting background processing for Master Slip ID: {clean_id}")

    # 5. Run generation in background
    def background_processing():
        print(f"\n🎬 BACKGROUND PROCESS STARTED")
        result = app.state.slip_builder.generate(payload)
        
        print(f"📦 Generation complete, preparing callback...")
        
        # Ensure the final webhook payload maintains the integer type
        final_packet = {
            "success": True,
            "master_slip_id": int(result.get("master_slip_id", clean_id)),
            "generated_slips": result.get("generated_slips", []),
            "metadata": result.get("metadata", {})
        }
        
        print(f"📤 Sending callback to: {full_callback_url}")
        print(f"📊 Sending {len(final_packet['generated_slips'])} slips")
        
        # Execute the webhook
        import asyncio
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        loop.run_until_complete(post_to_callback(full_callback_url, final_packet))
        loop.close()
        print("✅ Callback sent successfully")

    background_tasks.add_task(background_processing)

    return {
        "status": "processing",
        "success": True,
        "message": "Generation started. Results will be posted to the callback URL.",
        "master_slip_id": clean_id,
        "callback_url": full_callback_url
    }

@app.post("/api/v1/analyze-match")
async def analyze_match(request: Request):
    """
    Detailed match analysis for the UI. 
    Can handle synthetic market predictions if data is missing.
    """
    try:
        raw_payload = await request.json()
        match_data = raw_payload.get("data", raw_payload) # Handle wrapped or unwrapped data
        
        # Execute using the state-stored engine
        result = app.state.insight_engine.analyze_single_match(match_data)
        
        return {
            "status": "success",
            "analysis": result
        }
    except Exception as e:
        logger.error(f"Match Analysis Route Failure: {str(e)}")
        return JSONResponse(
            status_code=400,
            content={"status": "error", "message": "Failed to analyze match data"}
        )

@app.get("/health")
async def health_check():
    return {"status": "online", "timestamp": time.time()}

if __name__ == "__main__":
    uvicorn.run("game_engine.app:app", host="0.0.0.0", port=5000, reload=True)