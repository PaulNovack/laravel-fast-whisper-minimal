<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta property="og:title" content="WebRTC + VAD + Faster-Whisper" />
    <title>WebRTC + VAD + Faster-Whisper</title>
    @vite(['resources/js/app.ts'])
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        [v-cloak]{display:none}
        body { font-family: system-ui, sans-serif; margin: 2rem; }
        button { padding: .6rem 1rem; margin-right: .5rem; }
        .log { white-space: pre-wrap; background: #111; color: #0f0; padding: 1rem; border-radius: .5rem; height: 14rem; overflow:auto; }
        .chip { display:inline-block; background:#eef; color:#224; padding:.25rem .5rem; border-radius:999px; margin:.25rem .25rem 0 0; }
    </style>
</head>
<body>
<div id="app" v-cloak>
    <h1>WebRTC end-of-speech + Reverb + Faster-Whisper</h1>

    <div style="margin-bottom: 12px">
        <span class="chip">Session: @{{ sessionId }}</span>
        <button @click="init" :disabled="inited">Init Mic</button>
        <button @click="start" :disabled="!inited || recording">Start</button>
        <button @click="stop"  :disabled="!recording">Stop</button>
    </div>

    <p>Status: <strong>@{{ status }}</strong></p>
    <p>Events:</p>
    <div class="log">@{{ logs.join('\n') }}</div>

    <audio ref="player" controls style="margin-top:12px; width: 100%"></audio>
</div>
</body>
</html>
