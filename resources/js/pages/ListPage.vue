<template>
    <section class="mx-auto max-w-3xl space-y-1">
        <header class="space-y-1">
            <h1 class="text-lg font-bold">Your List</h1>
            <p class="text-gray-600 dark:text-gray-300">Add, remove, or clear items by text or voice.</p>
        </header>


        <!-- LIST -->
        <div class="rounded-2xl border border-gray-200 p-4 shadow-sm dark:border-gray-800">
            <p v-if="heard" class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Heard: <span class="font-medium text-gray-700 dark:text-gray-200">{{ heard }}</span>
            </p>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-md font-semibold">Items ({{ items.length }})</h2>
                <button
                    class="rounded-xl border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                    @click="clearList"
                >
                    Clear list
                </button>
            </div>

            <div v-if="items.length === 0" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                No items yet. Try <span class="font-medium">add 2 tomatoe sauce</span>.
            </div>

            <ul v-else class="divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                <li v-for="it in items" :key="it" class="flex items-center justify-between px-1.5 py-1.5">
                    <span class="text-[16px]">{{ it }}</span>
                    <button
                        class="rounded bg-rose-50 px-1 py-1 text-sm font-medium text-rose-700 transition
                        hover:bg-rose-100 dark:bg-rose-900/30 dark:text-rose-200 dark:hover:bg-rose-900/40"
                        @click="removeItem(it)"
                    >
                        Remove
                    </button>
                </li>
            </ul>
        </div>
        <!-- AUTO-VOICE (VAD) -->
        <div class="rounded-2xl border border-gray-200 p-4 shadow-sm dark:border-gray-800">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Auto-voice (VAD)</h2>
                <div class="flex items-center gap-2">
                    <button
                        class="touch-target rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50"
                        :disabled="active"
                        @click="startVad"
                    >Start</button>
                    <button
                        class="touch-target rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-500 disabled:opacity-50"
                        :disabled="!active"
                        @click="stopVad"
                    >Stop</button>
                </div>
            </div>

            <!-- Level meter -->
            <div class="mt-2">
                <div class="mb-1 text-xs text-gray-500 dark:text-gray-400">
                    Level: {{ levelDb.toFixed(1) }} dB
                </div>
                <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-gray-800">
                    <div
                        class="h-2 rounded-full bg-indigo-500 transition-[width]"
                        :style="{ width: `${Math.max(0, Math.min(100, ((levelDb + 90) / 90) * 100)).toFixed(0)}%` }"
                    />
                </div>
            </div>

            <p v-if="vadError" class="mt-3 text-sm text-red-600">{{ vadError }}</p>

            <!-- Compact log -->
            <div class="mt-3 max-h-28 overflow-auto rounded-lg border border-gray-200 p-2 text-xs font-mono text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <div v-if="vadLogs.length === 0" class="opacity-60">VAD idle. Click Start and speak; pausing will auto-send.</div>
                <div v-for="(l,i) in vadLogs" :key="i">{{ l }}</div>
            </div>
        </div>
        <!-- TEXT COMMAND -->
        <div class="rounded-2xl border border-gray-200 p-4 shadow-sm dark:border-gray-800">
            <h2 class="mb-3 text-lg font-semibold">Text command</h2>
            <form @submit.prevent="sendText" class="flex flex-col gap-3 sm:flex-row">
                <input
                    v-model="commandText"
                    type="text"
                    autocomplete="off"
                    placeholder='e.g. add 2 tomatoe sauce, remove apples, clear list'
                    class="w-full rounded-xl border border-gray-300 px-4 py-3 text-[16px] placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900"
                />
                <button
                    :disabled="loadingText || !commandText.trim()"
                    class="rounded-xl bg-indigo-600 px-5 py-3 text-[16px] font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ loadingText ? 'Working…' : 'Send' }}
                </button>
            </form>


            <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
        </div>
        <!-- AUDIO (UPLOAD + MANUAL RECORD) -->
        <div class="rounded-2xl border border-gray-200 p-2 shadow-sm dark:border-gray-800">
            <h2 class="mb-3 text-lg font-semibold">Audio command</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <!-- Upload -->
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                    <p class="mb-2 text-sm text-gray-600 dark:text-gray-300">Upload audio file</p>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="audio/*"
                        class="block w-full cursor-pointer rounded-lg border border-gray-300 p-2.5 text-sm dark:border-gray-700 dark:bg-gray-900"
                        @change="uploadSelected"
                    />
                    <button
                        :disabled="loadingAudio || !selectedFile"
                        class="mt-2 w-full rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:opacity-50"
                        @click="sendFile"
                    >
                        {{ loadingAudio ? 'Uploading…' : 'Send file' }}
                    </button>
                </div>

                <!-- Manual record -->
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                    <p class="mb-2 text-sm text-gray-600 dark:text-gray-300">Record a quick command</p>
                    <div class="flex items-center gap-2">
                        <button
                            class="touch-target rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50"
                            :disabled="recording || !canRecord"
                            @click="startRecording"
                        >
                            ▶ Start
                        </button>
                        <button
                            class="touch-target rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-500 disabled:opacity-50"
                            :disabled="!recording"
                            @click="stopRecording"
                        >
                            ■ Stop
                        </button>
                        <span class="text-sm text-gray-600 dark:text-gray-300">
              {{ recording ? `Recording… ${elapsed}s` : (canRecord ? 'Idle' : 'Recording not supported') }}
            </span>
                    </div>

                    <div v-if="recordedUrl" class="mt-3 space-y-2">
                        <audio :src="recordedUrl" controls class="w-full"></audio>
                        <button
                            :disabled="loadingAudio || !recordedBlob"
                            class="w-full rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:opacity-50"
                            @click="sendRecording"
                        >
                            {{ loadingAudio ? 'Sending…' : 'Send recording' }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="errorAudio" class="mt-3 text-sm text-red-600">{{ errorAudio }}</p>
        </div>


    </section>
</template>

<script setup lang="ts">
// If your alias '@' is not set to '/resources/js', change this import to a relative path.
import { useVad } from '@/composables/useVad'
import { ref, onMounted, onBeforeUnmount } from 'vue'

type FromTextResp = { heard?: string; action: string; items: string[] }
type FromAudioResp = { heard?: string; action: string; items: string[] }

const items = ref<string[]>([])
const heard = ref<string>('')

const commandText = ref<string>('')
const loadingText = ref(false)
const loadingAudio = ref(false)
const error = ref<string>('')

const fileInput = ref<HTMLInputElement | null>(null)
const selectedFile = ref<File | null>(null)
const errorAudio = ref<string>('')

// ---- Helpers ----
const csrf = () =>
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''

async function loadItems() {
    await sendTextCommand('noop', { suppressHeard: true })
}

async function sendText() {
    await sendTextCommand(commandText.value)
    commandText.value = ''
}

async function sendTextCommand(text: string, opts: { suppressHeard?: boolean } = {}) {
    error.value = ''
    loadingText.value = true
    try {
        const res = await fetch('/list/from-text', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ text }),
            credentials: 'same-origin',
        })
        if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)
        const json = (await res.json()) as FromTextResp
        items.value = json.items || []
        if (!opts.suppressHeard) heard.value = json.heard || text
    } catch (e: any) {
        error.value = e?.message || 'Request failed'
    } finally {
        loadingText.value = false
    }
}

function uploadSelected(e: Event) {
    const t = e.target as HTMLInputElement
    selectedFile.value = t.files?.[0] ?? null
}

async function sendFile() {
    if (!selectedFile.value) return
    await sendAudioBlob(selectedFile.value, selectedFile.value.type)
    if (fileInput.value) fileInput.value.value = ''
    selectedFile.value = null
}

async function sendRecording() {
    if (!recordedBlob.value) return
    await sendAudioBlob(recordedBlob.value, recordedMime.value)
}

async function sendAudioBlob(blob: Blob, mimeOverride?: string) {
    errorAudio.value = ''
    loadingAudio.value = true
    try {
        const fd = new FormData()
        const ext = mimeOverride?.includes('webm') ? 'webm' : (mimeOverride?.includes('ogg') ? 'ogg' : 'wav')
        fd.append('audio', blob, `command.${ext}`)

        const res = await fetch('/list/from-audio', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: fd,
            credentials: 'same-origin',
        })
        if (!res.ok) {
            const text = await res.text()
            throw new Error(`${res.status} ${res.statusText}: ${text}`)
        }
        const json = (await res.json()) as FromAudioResp
        items.value = json.items || []
        heard.value = json.heard || '(audio)'
    } catch (e: any) {
        errorAudio.value = e?.message || 'Audio request failed'
    } finally {
        loadingAudio.value = false
    }
}

// ---- Manual MediaRecorder (optional alternative to VAD) ----
const canRecord = 'MediaRecorder' in window
const recording = ref(false)
const recordedChunks: BlobPart[] = []
const recordedBlob = ref<Blob | null>(null)
const recordedUrl = ref<string | null>(null)
const recordedMime = ref<string>('audio/webm')
const elapsed = ref(0)
let rec: MediaRecorder | null = null
let tick: number | null = null

function startTimer() {
    elapsed.value = 0
    tick = window.setInterval(() => (elapsed.value += 1), 1000)
}
function stopTimer() {
    if (tick) { clearInterval(tick); tick = null }
}

async function startRecording() {
    if (!canRecord) return
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
        const mime =
            MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' :
                (MediaRecorder.isTypeSupported('audio/ogg') ? 'audio/ogg' : '')
        recordedMime.value = mime || 'audio/webm'

        rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined)
        recordedChunks.length = 0
        rec.ondataavailable = (e) => { if (e.data?.size) recordedChunks.push(e.data) }
        rec.onstop = () => {
            const blob = new Blob(recordedChunks, { type: recordedMime.value })
            recordedBlob.value = blob
            recordedUrl.value = URL.createObjectURL(blob)
            stream.getTracks().forEach(t => t.stop())
            stopTimer()
            recording.value = false
        }
        rec.start()
        recording.value = true
        startTimer()
    } catch (e: any) {
        errorAudio.value = e?.message || 'Microphone permission denied'
    }
}
function stopRecording() {
    if (!rec || rec.state !== 'recording') return
    rec.stop()
}

// ---- Automatic VAD wiring ----
const vadPostUrl = '/list/from-audio'
const { start, stop, active, levelDb, logs: vadLogs, error: vadError } = useVad({
    postUrl: vadPostUrl,
    csrfToken: csrf(),
    onResult: (json) => {
        items.value = json.items || []
        heard.value = json.heard || '(audio)'
    },
})
const startVad = () => start()
const stopVad = () => stop()

onMounted(loadItems)
onBeforeUnmount(() => {
    if (recordedUrl.value) URL.revokeObjectURL(recordedUrl.value!)
    stopVad()
})

async function removeItem(it: string) {
    // pass the exact label; your service handles exact/fuzzy match
    await sendTextCommand(`remove ${it}`)
}

async function clearList() {
    await sendTextCommand('clear list')
}
</script>

<style scoped>
@reference "tailwindcss";

/* Larger base size already handled globally, ensure touch areas here */
.touch-target { min-height: 44px; min-width: 44px; }
button {
    padding: .1rem .5rem; /* global override */
}

</style>
