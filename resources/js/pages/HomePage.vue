<script setup lang="ts">
import { ref } from 'vue'
import { startContinuousUtterances, stopAll } from '@/webrtc-page'

const logs = ref<string[]>([])
const text = ref('')
const timeMs = ref<number | null>(null)
const db = ref(-100)

function log(m: string) {
    logs.value.unshift(`[${new Date().toLocaleTimeString()}] ${m}`)
    if (logs.value.length > 500) logs.value.pop()
}

async function start() {
    text.value = ''
    timeMs.value = null

    await startContinuousUtterances({
        postUrl: '/api/transcribe',
        onText: (result: { text?: string; time_ms?: number } | string) => {
            if (typeof result === 'string') {
                // backward-compat fallback
                text.value = result
                log('ASR done')
            } else {
                text.value = result.text ?? ''
                timeMs.value = typeof result.time_ms === 'number' ? result.time_ms : null
                if (timeMs.value != null) log(`ASR done in ${timeMs.value} ms`)
                else log('ASR done')
            }
        },
        onLevel: (v: number) => { db.value = v },
        onLog: log,
    })
}

function stop() {
    stopAll()
    log('Stopped all')
}
</script>

<template>
    <div class="p-6 space-y-4">
        <h1 class="text-2xl font-bold">Speak, pause, transcribe</h1>

        <div class="flex gap-3">
            <button class="px-4 py-2 rounded bg-black text-white" @click="start">Start</button>
            <button class="px-4 py-2 rounded bg-gray-200" @click="stop">Stop</button>
        </div>

        <div>
            <h2 class="font-semibold mb-1">Transcript</h2>
            <p class="p-3 bg-white rounded border">{{ text || '—' }}</p>
            <p v-if="timeMs !== null" class="text-sm text-gray-600 mt-2">
                ⏱️ Processed in {{ Math.round(timeMs) }} ms
            </p>
        </div>

        <div class="text-sm">Level: <span class="font-mono">{{ db.toFixed(1) }} dB</span></div>

        <div>
            <h2 class="font-semibold mb-1">Logs</h2>
            <pre class="bg-gray-100 p-3 rounded h-60 overflow-auto">{{ logs.join('\n') }}</pre>
        </div>
    </div>
</template>
<style scoped>
@reference "tailwindcss";
</style>
