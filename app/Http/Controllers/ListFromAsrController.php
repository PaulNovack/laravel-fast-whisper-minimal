<?php

namespace App\Http\Controllers;

use App\Services\AsrService;
use App\Services\ListService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ListFromAsrController extends Controller
{
    public function __invoke(Request $request, AsrService $asr, ListService $list)
    {
        if (!$request->hasFile('audio')) {
            return response()->json(['error' => 'No audio uploaded'], 400);
        }

        $file = $request->file('audio');

        $asrResult = $asr->transcribeUploadedFile($file); // ['text','time_ms','e2e_ms']
        $text = $asrResult['text'] ?? '';

        $result = $list->processCommand($text);

        return response()->json([
            'heard'   => $text,
            'action'  => $result['action'],
            'items'   => $result['items'],
            'model_ms'=> $asrResult['time_ms'] ?? null,
            'e2e_ms'  => $asrResult['e2e_ms'] ?? null,
        ]);
    }
}
