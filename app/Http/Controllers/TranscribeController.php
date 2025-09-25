<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TranscribeController extends Controller
{
    public function __invoke(Request $request)
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

            $path = $file->storeAs(
                'transient',
                uniqid('utt_') . '.' . $file->getClientOriginalExtension(),
                'local'
            );
            $full = Storage::disk('local')->path($path);

            $start = microtime(true);
            $asrUrl = rtrim(env('ASR_URL', 'http://asr:9000'), '/') . '/transcribe';

            $resp = Http::timeout(180)->attach(
                'audio', file_get_contents($full), basename($full)
            )->post($asrUrl);

            $e2eMs = (microtime(true) - $start) * 1000;

            if ($resp->failed()) {
                Log::error('Transcribe: ASR service error', [
                    'status'  => $resp->status(),
                    'body'    => $resp->body(),
                    'e2e_ms'  => round($e2eMs, 1),
                    'asr_url' => $asrUrl,
                ]);
                return response()->json([
                    'error'  => 'ASR service error',
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ], 502);
            }

            $json = $resp->json() ?? [];
            Log::info('Transcribe: success', [
                'text_preview' => mb_substr($json['text'] ?? '', 0, 80),
                'model_ms'     => $json['time_ms'] ?? null,
                'e2e_ms'       => round($e2eMs, 1),
            ]);

            @unlink($full);

            return response()->json([
                'text'    => $json['text'] ?? '',
                'time_ms' => $json['time_ms'] ?? null,
                'e2e_ms'  => round($e2eMs, 1),
            ]);
        } catch (\Throwable $e) {
            Log::error('Transcribe: exception', [
                'msg'   => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
