<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AsrService
{
    private string $route;
    private string $baseUrl;

    private int $timeoutSeconds;
    public function __construct(){
        $this->route = '/transcribe';
        $this->baseUrl = env('ASR_URL');
        $this->timeoutSeconds = 10;
    }

    /**
     * Accepts an UploadedFile, stores it transiently, calls ASR, and cleans up.
     *
     * @return array{text:string,time_ms:int|null,e2e_ms:float}
     */
    public function transcribeUploadedFile(UploadedFile $file): array
    {
        $path = $file->storeAs(
            'transient',
            uniqid('utt_', true) . '.' . $file->getClientOriginalExtension(),
            'local'
        );

        $full = Storage::disk('local')->path($path);

        try {
            return $this->transcribePath($full);
        } finally {
            @unlink($full); // best-effort cleanup
        }
    }

    /**
     * Calls the ASR endpoint with a local file path.
     *
     * @return array{text:string,time_ms:int|null,e2e_ms:float}
     *
     * @throws \RuntimeException when upstream fails
     */
    public function transcribePath(string $fullPath): array
    {
        $url   = $this->baseUrl . $this->route;
        $start = microtime(true);

        $resp = Http::timeout($this->timeoutSeconds)->attach(
            'audio',
            file_get_contents($fullPath),
            basename($fullPath)
        )->post($url);

        $e2eMs = (microtime(true) - $start) * 1000;

        if ($resp->failed()) {
            Log::error('ASR: service error', [
                'status'  => $resp->status(),
                'body'    => $resp->body(),
                'e2e_ms'  => round($e2eMs, 1),
                'asr_url' => $url,
            ]);

            throw new \RuntimeException('ASR service error: ' . $resp->status());
        }

        $json = $resp->json() ?? [];

        Log::info('ASR: success', [
            'text_preview' => mb_substr($json['text'] ?? '', 0, 80),
            'model_ms'     => $json['time_ms'] ?? null,
            'e2e_ms'       => round($e2eMs, 1),
        ]);

        return [
            'text'    => (string)($json['text'] ?? ''),
            'time_ms' => $json['time_ms'] ?? null,
            'e2e_ms'  => round($e2eMs, 1),
        ];
    }
}
