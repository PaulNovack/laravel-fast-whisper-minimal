<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Events\RecordingEvent;
use App\Jobs\TranscribeAudioJob;

class AudioUploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'sessionId' => 'required|string',
            'audio'     => 'required|file|max:51200',
            'mime'      => 'nullable|string',
            'model'     => 'nullable|string'
        ]);

        $id = Str::uuid()->toString();
        $ext = $this->guessExt($request->input('mime')) ?? $request->file('audio')->extension() ?? 'wav';

        $relPath = "recordings/{$id}.{$ext}";
        Storage::disk('local')->putFileAs('recordings', $request->file('audio'), "{$id}.{$ext}");

        broadcast(new RecordingEvent(
            sessionId: $request->input('sessionId'),
            type: 'stored',
            payload: ['id' => $id, 'path' => $relPath, 'mime' => $request->input('mime')]
        ))->toOthers();

        $absPath = Storage::disk('local')->path($relPath);
        TranscribeAudioJob::dispatch(
            sessionId: $request->input('sessionId'),
            id: $id,
            absolutePath: $absPath,
            modelSize: $request->input('model', 'base')
        );

        return response()->json(['id' => $id, 'path' => $relPath]);
    }

    private function guessExt(?string $mime): ?string
    {
        return match ($mime) {
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/webm'               => 'webm',
            'audio/ogg'                => 'ogg',
            'audio/mpeg'               => 'mp3',
            default                    => null,
        };
    }
}
