<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::view('/', 'webrtc-demo');

Route::post('/audio/chunk', function (Request $request) {

    $path = storage_path('app/public/chunks');
    if (!is_dir($path)) mkdir($path, 0775, true);
    file_put_contents($path.'/'.uniqid('chunk_').'.webm', $request->getContent());

    return response()->json(['ok' => true]);
});

require __DIR__.'/settings.php';

