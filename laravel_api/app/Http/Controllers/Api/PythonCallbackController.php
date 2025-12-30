<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\StoreGeneratedSlips;
use App\Models\MasterSlip;
use Illuminate\Support\Facades\Log;

class PythonCallbackController extends Controller
{
    public function handleCallback(Request $request, $masterSlipId)
    {
        Log::info('🔄 PYTHON CALLBACK RECEIVED', [
            'master_slip_id' => $masterSlipId,
            'request_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'full_url' => $request->fullUrl(),
            'request_time' => now()->toDateTimeString(),
        ]);

        // Log full request for debugging
        Log::debug('📥 Raw request data:', [
            'all_headers' => $request->headers->all(),
            'full_payload' => $request->all(),
        ]);

        try {
            // Validate required fields
            $validated = $request->validate([
                'success' => 'required|boolean',
                'generated_slips' => 'required|array',
                'generated_slips.*' => 'array',
                'metadata' => 'required|array',
            ]);

            Log::info('✅ Payload validation passed', [
                'master_slip_id' => $masterSlipId,
                'has_success_field' => isset($validated['success']),
                'slips_count' => count($validated['generated_slips']),
                'metadata_keys' => array_keys($validated['metadata']),
            ]);

            // Check if Python reported success
            if (!$validated['success']) {
                Log::error('❌ Python callback reported failure', [
                    'master_slip_id' => $masterSlipId,
                    'python_error' => $validated['error'] ?? 'Unknown',
                    'python_status' => $validated['status'] ?? 'none',
                    'full_metadata' => $validated['metadata'],
                ]);

                // Try to find master slip even if ID might be wrong
                $masterSlip = MasterSlip::find($masterSlipId);
                
                if ($masterSlip) {
                    $masterSlip->update([
                        'status' => 'failed',
                        'error_message' => 'Python engine failed: ' . ($validated['error'] ?? 'Unknown'),
                        'failed_at' => now(),
                    ]);
                    Log::warning('📝 Master slip status updated to failed', [
                        'master_slip_id' => $masterSlipId,
                    ]);
                } else {
                    Log::critical('💥 Master slip not found for failure update', [
                        'master_slip_id' => $masterSlipId,
                        'available_ids' => MasterSlip::pluck('id')->take(10)->toArray(),
                    ]);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Python processing failed',
                    'master_slip_id' => $masterSlipId,
                    'python_error' => $validated['error'] ?? 'Unknown',
                ], 400);
            }

            // Verify we have actual slip data
            $slipCount = count($validated['generated_slips']);
            if ($slipCount === 0) {
                Log::warning('⚠️ Python reported success but generated_slips array is empty', [
                    'master_slip_id' => $masterSlipId,
                    'metadata' => $validated['metadata'],
                ]);
            }

            // Log sample slip structure for debugging
            if ($slipCount > 0) {
                $sampleSlip = $validated['generated_slips'][0];
                Log::debug('📋 Sample slip structure:', [
                    'slip_id' => $sampleSlip['slip_id'] ?? 'missing',
                    'has_legs' => isset($sampleSlip['legs']),
                    'legs_count' => isset($sampleSlip['legs']) ? count($sampleSlip['legs']) : 0,
                    'total_odds' => $sampleSlip['total_odds'] ?? 'missing',
                    'keys' => array_keys($sampleSlip),
                ]);
            }

            // Check if master slip exists before dispatching
            $masterSlip = MasterSlip::find($masterSlipId);
            
            if (!$masterSlip) {
                Log::critical('💥 MASTER SLIP NOT FOUND IN DATABASE', [
                    'master_slip_id' => $masterSlipId,
                    'python_master_slip_id' => $validated['metadata']['master_slip_id'] ?? 'not_provided',
                    'total_slips_from_python' => $slipCount,
                    'database_check' => 'MasterSlip::find(' . $masterSlipId . ') returned null',
                ]);
                
                // Still dispatch job but log warning
                Log::warning('⚠️ Dispatching StoreGeneratedSlips even though master slip not found');
            } else {
                Log::info('📁 Master slip found, updating status', [
                    'master_slip_id' => $masterSlipId,
                    'current_status' => $masterSlip->status,
                    'custom_id' => $masterSlip->custom_id ?? 'none',
                ]);
                
                // Update status to show callback received
                $masterSlip->update([
                    'status' => 'callback_received',
                    'callback_received_at' => now(),
                    'generated_slips_count' => $slipCount,
                ]);
            }

            // Dispatch job to store generated slips
            StoreGeneratedSlips::dispatch($masterSlipId, $validated);
            
            Log::info('🚀 StoreGeneratedSlips job dispatched', [
                'master_slip_id' => $masterSlipId,
                'slips_count' => $slipCount,
                'queue' => 'python_engine',
                'dispatch_time' => now()->toDateTimeString(),
                'metadata_version' => $validated['metadata']['engine_version'] ?? 'unknown',
            ]);

            // Log detailed slip IDs for tracking
            if ($slipCount > 0) {
                $slipIds = array_column($validated['generated_slips'], 'slip_id');
                Log::info('📝 Slip IDs to be stored:', [
                    'first_5_slips' => array_slice($slipIds, 0, 5),
                    'total_unique_ids' => count(array_unique($slipIds)),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Callback received, storing slips',
                'master_slip_id' => $masterSlipId,
                'slips_received' => $slipCount,
                'storage_job_dispatched' => true,
                'timestamp' => now()->toDateTimeString(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('❌ VALIDATION FAILED in Python callback', [
                'master_slip_id' => $masterSlipId,
                'errors' => $e->errors(),
                'received_data_keys' => array_keys($request->all()),
                'required_fields_missing' => array_diff(['success', 'generated_slips', 'metadata'], array_keys($request->all())),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid payload structure',
                'errors' => $e->errors(),
                'required_fields' => ['success (boolean)', 'generated_slips (array)', 'metadata (array)'],
            ], 422);
            
        } catch (\Exception $e) {
            Log::critical('💥 UNEXPECTED ERROR in Python callback', [
                'master_slip_id' => $masterSlipId,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error processing callback',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }
}