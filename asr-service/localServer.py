# asr-service/server.py
import os, time, tempfile
from contextlib import asynccontextmanager
from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.responses import JSONResponse
from faster_whisper import WhisperModel

ASR_MODEL   = os.getenv("ASR_MODEL", "small")
ASR_DEVICE  = os.getenv("ASR_DEVICE", "cpu")                  # cpu | cuda
ASR_COMPUTE = os.getenv("ASR_COMPUTE", "int8")                # int8 | int8_float32 | float16 | float32
ASR_LANG    = os.getenv("ASR_LANG", "en")
ASR_PORT    = int(os.getenv("ASR_PORT", "9000"))

# NEW: control CPU threads per process and internal worker pool
ASR_CPU_THREADS = int(os.getenv("ASR_CPU_THREADS", "4"))      # per-process compute threads
ASR_NUM_WORKERS = int(os.getenv("ASR_NUM_WORKERS", "1"))      # internal parallel decoders

@asynccontextmanager
async def lifespan(app: FastAPI):
    model = WhisperModel(
        ASR_MODEL,
        device=ASR_DEVICE,
        compute_type=ASR_COMPUTE,
        cpu_threads=ASR_CPU_THREADS,   # <—
        num_workers=ASR_NUM_WORKERS    # <—
    )
    app.state.model = model
    try:
        yield
    finally:
        try:
            del app.state.model
        except Exception:
            pass

app = FastAPI(title="Faster-Whisper Service", lifespan=lifespan)

@app.get("/health")
def health():
    return {
        "ok": True,
        "model": ASR_MODEL,
        "device": ASR_DEVICE,
        "compute": ASR_COMPUTE,
        "cpu_threads": ASR_CPU_THREADS,
        "num_workers": ASR_NUM_WORKERS,
    }

@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...)):
    if not audio or not audio.filename:
        raise HTTPException(status_code=400, detail="No audio file")
    import os, time, tempfile
    with tempfile.NamedTemporaryFile(suffix=f"_{os.path.basename(audio.filename)}", delete=False) as tmp:
        tmp.write(await audio.read())
        tmp_path = tmp.name

    t0 = time.time()
    try:
        segments, info = app.state.model.transcribe(
            tmp_path,
            language=ASR_LANG,
            vad_filter=True
        )
        text = "".join(s.text for s in segments).strip()
        return JSONResponse({
            "text": text,
            "time_ms": int((time.time() - t0) * 1000),
            "language": getattr(info, "language", None),
            "language_probability": getattr(info, "language_probability", None),
        })
    finally:
        try: os.remove(tmp_path)
        except: pass

if __name__ == "__main__":
    import uvicorn
    # For dev only; for prod, use the commands shown below.
    uvicorn.run("server:app", host="0.0.0.0", port=ASR_PORT, reload=False)

