# asr-service/server.py
import os, time, tempfile
from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.responses import JSONResponse
from faster_whisper import WhisperModel

ASR_MODEL   = os.getenv("ASR_MODEL", "small")
ASR_DEVICE  = os.getenv("ASR_DEVICE", "cpu")            # cpu | cuda
ASR_COMPUTE = os.getenv("ASR_COMPUTE", "int8")          # int8 | int8_float32 | float16 | float32
ASR_LANG    = os.getenv("ASR_LANG", "en")

app = FastAPI(title="Faster-Whisper Service")  # <-- uvicorn loads this

@app.on_event("startup")
def load_model():
    # load once on startup
    app.state.model = WhisperModel(
        ASR_MODEL,
        device=ASR_DEVICE,
        compute_type=ASR_COMPUTE
    )

@app.get("/health")
def health():
    return {"ok": True, "model": ASR_MODEL, "device": ASR_DEVICE, "compute": ASR_COMPUTE}

@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...)):
    if not audio:
        raise HTTPException(400, "No audio file")

    with tempfile.NamedTemporaryFile(suffix=f"_{audio.filename}", delete=False) as tmp:
        data = await audio.read()
        tmp.write(data)
        tmp_path = tmp.name

    t0 = time.time()
    try:
        segments, info = app.state.model.transcribe(tmp_path, language=ASR_LANG, vad_filter=True)
        text = "".join(s.text for s in segments).strip()
        elapsed_ms = int((time.time() - t0) * 1000)
        return JSONResponse({"text": text, "time_ms": elapsed_ms})
    except Exception as e:
        raise HTTPException(500, f"ASR failed: {e}")
    finally:
        try: os.remove(tmp_path)
        except Exception: pass
