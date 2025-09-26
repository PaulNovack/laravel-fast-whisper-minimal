<?php

namespace App\Http\Controllers;

use App\Services\AsrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TranscribeController extends Controller
{
    public function __invoke(Request $request, AsrService $asr)
    {
        try {
            if (!$request->hasFile('audio')) {
                Log::warning('Transcribe: no file in request', [
                    'content_type' => $request->header('Content-Type'),
                ]);
                return response()->json(['error' => 'No audio uploaded'], 400);
            }

            $file = $request->file('audio');

            Log::info('Transcribe: incoming file', [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);

            $result = $asr->transcribeUploadedFile($file);

            return response()->json($result);

        } catch (\RuntimeException $e) {
            // Upstream ASR failure → 502 like before
            return response()->json([
                'error' => 'ASR service error',
                'msg'   => $e->getMessage(),
            ], 502);
        } catch (\Throwable $e) {
            Log::error('Transcribe: exception', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
