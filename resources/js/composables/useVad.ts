// Adaptive VAD + continuous utterances → posts to your endpoint on pause.
// Usage: const { start, stop, active, levelDb, logs } = useVad({ postUrl, onResult })

import { ref } from 'vue'

type VADOptions = {
    // recorder
    timeslice?: number
    mimeTypeCandidates?: string[]

    // VAD params
    vadWarmupMs?: number
    marginDb?: number
    hysteresisDb?: number
    minUtteranceMs?: number
    maxUtteranceMs?: number
    hangAfterSpeechMs?: number

    // posting
    postUrl: string
    csrfToken?: string
    onResult?: (json: any) => void
    onLog?: (line: string) => void
}

export function useVad(opts: VADOptions) {
    const options: Required<Omit<VADOptions, 'csrfToken' | 'onResult' | 'onLog'>> = {
        timeslice: opts.timeslice ?? 250,
        mimeTypeCandidates: opts.mimeTypeCandidates ?? ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'],
        vadWarmupMs: opts.vadWarmupMs ?? 400,
        marginDb: opts.marginDb ?? 6,
        hysteresisDb: opts.hysteresisDb ?? 3,
        minUtteranceMs: opts.minUtteranceMs ?? 600,
        maxUtteranceMs: opts.maxUtteranceMs ?? 15000,
        hangAfterSpeechMs: opts.hangAfterSpeechMs ?? 600,
        postUrl: opts.postUrl,
    }

    const active = ref(false)
    const levelDb = ref(-80)
    const logs = ref<string[]>([])
    const error = ref<string>('')

    let stream: MediaStream | null = null
    let mediaRecorder: MediaRecorder | null = null
    let audioCtx: AudioContext | null = null
    let analyser: AnalyserNode | null = null
    let rafId: number | null = null
    let buffered: BlobPart[] = []
    let startedAt = 0
    let inSpeech = false
    let lastSpeechAt = 0
    let warmupEnd = 0
    let noiseFloorDb = -55

    const isTypeSupported = (m: string) =>
        !!(window.MediaRecorder && (MediaRecorder as any).isTypeSupported && MediaRecorder.isTypeSupported(m))

    const log = (s: string) => {
        logs.value = [s, ...logs.value].slice(0, 40)
        opts.onLog?.(s)
    }

    const applyEMA = (prev: number, next: number, alpha: number) => prev + alpha * (next - prev)

    async function start() {
        if (active.value) return
        error.value = ''
        logs.value = []
        try {
            const mimeType = options.mimeTypeCandidates.find(isTypeSupported) || ''
            log(`VAD starting (mime=${mimeType || 'default'}, slice=${options.timeslice}ms)`)

            stream = await navigator.mediaDevices.getUserMedia({
                audio: { channelCount: 1, noiseSuppression: true, echoCancellation: true },
            })

            await startRecorder(mimeType)
            await startAnalyser()

            active.value = true
            startedAt = performance.now()
            lastSpeechAt = startedAt
            warmupEnd = startedAt + options.vadWarmupMs
            inSpeech = false
            noiseFloorDb = -55

            loop()
        } catch (e: any) {
            error.value = e?.message || 'Failed to start VAD'
            log(`ERROR: ${error.value}`)
            await stop()
        }
    }

    async function startRecorder(mimeType: string) {
        buffered = []
        mediaRecorder = new MediaRecorder(stream!, mimeType ? { mimeType } : undefined)
        mediaRecorder.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) buffered.push(e.data)
        }
        mediaRecorder.start(options.timeslice)
        log('Recorder started')
    }

    async function startAnalyser() {
        audioCtx = audioCtx || new (window.AudioContext || (window as any).webkitAudioContext)()
        analyser = audioCtx.createAnalyser()
        analyser.fftSize = 2048
        const src = audioCtx.createMediaStreamSource(stream!)
        src.connect(analyser)
        log('Analyser started')
    }

    function bufferedSizeKb() {
        let size = 0
        for (const b of buffered) size += (b as any)?.size || 0
        return size / 1024
    }

    async function stopInternal(): Promise<Blob> {
        return new Promise((resolve) => {
            try {
                if (!mediaRecorder || mediaRecorder.state === 'inactive') return resolve(new Blob())
                mediaRecorder.addEventListener(
                    'stop',
                    () => {
                        const type = mediaRecorder?.mimeType || 'audio/webm'
                        const blob = new Blob(buffered, { type })
                        resolve(blob)
                    },
                    { once: true }
                )
                mediaRecorder.stop()
            } catch {
                resolve(new Blob())
            }
        })
    }

    async function finalizeAndPost() {
        const blob = await stopInternal()
        if (!blob || blob.size === 0) {
            log('skip post: empty blob')
            return
        }
        log(`posting ${ (blob.size / 1024).toFixed(1) } KB`)

        const fd = new FormData()
        const ext = blob.type.includes('webm') ? 'webm' : (blob.type.includes('ogg') ? 'ogg' : 'm4a')
        fd.append('audio', blob, `utt.${ext}`)

        try {
            const res = await fetch(options.postUrl, {
                method: 'POST',
                headers: {
                    ...(opts.csrfToken ? { 'X-CSRF-TOKEN': opts.csrfToken } : {}),
                    Accept: 'application/json',
                },
                body: fd,
                credentials: 'same-origin',
            })
            const json = await res.json().catch(() => ({}))
            if (!res.ok) {
                log(`ASR ${res.status}: ${JSON.stringify(json).slice(0, 140)}`)
            } else {
                opts.onResult?.(json)
            }
        } catch (e: any) {
            log(`post error: ${e?.message || e}`)
        }
    }

    async function restartRecorder() {
        if (!stream) return
        if (rafId) {
            cancelAnimationFrame(rafId)
            rafId = null
        }
        buffered = []
        startedAt = performance.now()
        lastSpeechAt = startedAt
        inSpeech = false
        // keep audioCtx/analyser alive for speed
        await startRecorder(mediaRecorder?.mimeType || '')
        loop()
    }

    function dbFromAnalyser(): number {
        const a = analyser!
        const buf = new Uint8Array(a.fftSize)
        a.getByteTimeDomainData(buf)
        let sum = 0
        for (let i = 0; i < buf.length; i++) {
            const v = (buf[i] - 128) / 128
            sum += v * v
        }
        const rms = Math.sqrt(sum / buf.length)
        const db = 20 * Math.log10(rms || 1e-8)
        return Math.max(-100, Math.min(0, db))
    }

    function loop() {
        if (!analyser || !active.value) return
        const db = dbFromAnalyser()
        levelDb.value = db

        const now = performance.now()
        const dur = now - startedAt

        // noise floor EMA: fast during warmup, slower after
        const alpha = now < warmupEnd ? 0.25 : 0.05
        noiseFloorDb = applyEMA(noiseFloorDb, db, alpha)
        const onThresh = noiseFloorDb + options.marginDb
        const offThresh = onThresh - options.hysteresisDb

        if (!inSpeech && db > onThresh) {
            inSpeech = true
            lastSpeechAt = now
            log(`speech ON db=${db.toFixed(1)} floor=${noiseFloorDb.toFixed(1)}`)
        } else if (inSpeech && db >= offThresh) {
            lastSpeechAt = now // still speaking
        }

        const sinceLast = now - lastSpeechAt
        const enough = dur >= options.minUtteranceMs

        // normal end: silence sustained after speech
        if (inSpeech && sinceLast >= options.hangAfterSpeechMs && enough) {
            finalizeAndPost().then(restartRecorder)
            return
        }

        // watchdog: very long utterance (no pause)
        if (dur >= options.maxUtteranceMs && bufferedSizeKb() > 5) {
            log('maxUtterance → post')
            finalizeAndPost().then(restartRecorder)
            return
        }

        rafId = requestAnimationFrame(loop)
    }

    async function stop() {
        active.value = false
        if (rafId) {
            cancelAnimationFrame(rafId)
            rafId = null
        }
        try {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop()
        } catch {}
        if (stream) {
            stream.getTracks().forEach((t) => t.stop())
            stream = null
        }
        // keep audioCtx open (faster next start), but you could close it:
        // await audioCtx?.close(); audioCtx = null
        log('VAD stopped')
    }

    return { start, stop, active, levelDb, logs, error }
}
