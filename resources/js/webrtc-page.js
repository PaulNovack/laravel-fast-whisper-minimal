// resources/js/webrtc-page.js
// Robust utterance capture with adaptive VAD + auto-post + auto-restart

let stream, mediaRecorder, audioCtx, analyser, rafId;
let recording = false;
let buffered = [];
let startedAt = 0;

function isTypeSupported(m) {
    return window.MediaRecorder
        && MediaRecorder.isTypeSupported
        && MediaRecorder.isTypeSupported(m);
}

export async function startContinuousUtterances({
                                                    // recorder
                                                    timeslice = 250,
                                                    mimeTypeCandidates = ['audio/webm;codecs=opus','audio/webm','audio/mp4'],

                                                    // VAD params (adaptive)
                                                    vadFpsMs = 16,                // ~RAF period
                                                    vadWarmupMs = 400,            // collect baseline before judging
                                                    marginDb = 6,                 // speech threshold over noise floor
                                                    hysteresisDb = 3,             // how much below threshold to consider "off"
                                                    minUtteranceMs = 600,         // don't post tiny blips
                                                    maxUtteranceMs = 15000,       // watchdog; post even if user never pauses
                                                    hangAfterSpeechMs = 600,      // grace time after last speech before posting

                                                    // posting
                                                    postUrl = '/api/transcribe',
                                                    onText = () => {},
                                                    onLevel = () => {},
                                                    onLog = () => {},
                                                } = {}) {
    if (recording) return;

    const mimeType = mimeTypeCandidates.find(isTypeSupported) || '';
    onLog(`mimeType=${mimeType || '(default)'}, timeslice=${timeslice}ms`);

    stream = await navigator.mediaDevices.getUserMedia({
        audio: { channelCount: 1, noiseSuppression: true, echoCancellation: true }
    });

    mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
    buffered = [];
    mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) buffered.push(e.data);
    };
    mediaRecorder.start(timeslice);
    recording = true;
    startedAt = performance.now();
    onLog('Recording…');

    // --- WebAudio for VAD ---
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    const src = audioCtx.createMediaStreamSource(stream);
    analyser = audioCtx.createAnalyser();
    analyser.fftSize = 2048;
    src.connect(analyser);

    const buf = new Uint8Array(analyser.fftSize);

    // Adaptive noise floor via exponential moving average
    // start conservative so we don't misfire while warming up
    let noiseFloorDb = -55;
    const ema = (prev, next, alpha) => prev + alpha * (next - prev);
    const warmupEnd = performance.now() + vadWarmupMs;

    let inSpeech = false;
    let lastSpeechAt = performance.now();
    let lastCheck = performance.now();

    const loop = async () => {
        analyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) {
            const v = (buf[i] - 128) / 128;
            sum += v * v;
        }
        const rms = Math.sqrt(sum / buf.length);
        const db = 20 * Math.log10(rms || 1e-8);
        onLevel(db);

        const now = performance.now();
        const dur = now - startedAt;

        // Update noise floor (faster while warming up, slower after)
        const alpha = now < warmupEnd ? 0.25 : 0.05;
        noiseFloorDb = ema(noiseFloorDb, db, alpha);
        const onThresh  = noiseFloorDb + marginDb;         // enter speech
        const offThresh = onThresh - hysteresisDb;         // leave speech

        // Speech state with hysteresis
        if (!inSpeech && db > onThresh) {
            inSpeech = true;
            lastSpeechAt = now;
            onLog(`speech ON (db=${db.toFixed(1)}, floor=${noiseFloorDb.toFixed(1)})`);
        } else if (inSpeech && db < offThresh) {
            // don't flip to silence immediately; the hangAfterSpeechMs controls posting
        } else if (inSpeech && db >= offThresh) {
            // still in speech; update lastSpeechAt
            lastSpeechAt = now;
        }

        // End conditions:
        const sinceLastSpeech = now - lastSpeechAt;
        const enoughToPost = dur >= minUtteranceMs;

        // 1) Normal end: silence sustained after speech
        if (inSpeech && sinceLastSpeech >= hangAfterSpeechMs && enoughToPost) {
            await finalizeAndPost();
            await restartRecorder();        // auto-restart for next utterance
            return;                         // stop this loop; restartRecorder starts a new one
        }

        // 2) Watchdog: max utterance length reached (user never pauses)
        if (dur >= maxUtteranceMs && bufferedSizeKb() > 5) {
            onLog('maxUtterance hit → posting');
            await finalizeAndPost();
            await restartRecorder();
            return;
        }

        // Pace loop ~ vadFpsMs
        //const delay = Math.max(0, vadFpsMs - (performance.now() - lastCheck));
        lastCheck = performance.now();
        rafId = requestAnimationFrame(loop);
    };

    async function finalizeAndPost() {
        const blob = await stopInternal();
        onLog(`posting utterance ${(blob.size/1024).toFixed(1)} KB`);
        if (blob.size === 0) { onLog('empty blob, skipping'); return; }

        const fd = new FormData();
        fd.append('audio', blob, `utt.${blob.type.includes('webm') ? 'webm' : 'm4a'}`);

        try {
            const res = await fetch(postUrl, { method: 'POST', body: fd });
            const json = await res.json().catch(() => ({}));
            if (json && typeof onText === 'function') onText(json);
            else onLog(`ASR no text. Status ${res.status}`);
        } catch (e) {
            onLog(`post error: ${e?.message || e}`);
        }
    }

    async function restartRecorder() {
        onLog('restarting for next utterance…');
        // Reset state and start again
        cancelAnimationFrame(rafId);
        rafId = null;
        // leave audioCtx open for speed
        buffered = [];
        startedAt = performance.now();
        inSpeech = false;

        mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
        mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size > 0) buffered.push(e.data); };
        mediaRecorder.start(timeslice);
        loop();
    }

    function bufferedSizeKb() {
        let size = 0;
        for (const b of buffered) size += b.size || 0;
        return size / 1024;
    }

    async function stopInternal() {
        return new Promise((resolve) => {
            try {
                if (!recording) return resolve(new Blob());
                // stop current recorder and flush last chunk
                mediaRecorder.addEventListener('stop', () => {
                    const blob = new Blob(buffered, { type: mediaRecorder.mimeType || 'audio/webm' });
                    resolve(blob);
                }, { once: true });
                mediaRecorder.stop();
            } catch {
                resolve(new Blob());
            }
        });
    }

    // Kick off VAD loop
    loop();
}

export async function stopAll() {
    // Force stop & do not post (UI can decide to post separately if needed)
    try {
        if (rafId) cancelAnimationFrame(rafId);
        rafId = null;
        if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
    } catch {}
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    recording = false;
}
