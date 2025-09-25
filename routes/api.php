<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TranscribeController;

Route::get('/transcribe', function () {
    return response()->json([
        'ok' => true,
        'message' => 'POST an audio file as multipart/form-data with field name "audio".'
    ]);
});

Route::post('/transcribe', TranscribeController::class);
