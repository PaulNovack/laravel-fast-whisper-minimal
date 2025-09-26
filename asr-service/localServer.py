# asr-service/server.py
import os, time, tempfile
from contextlib import asynccontextmanager
from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.responses import JSONResponse
from faster_whisper import WhisperModel

ASR_MODEL   = os.getenv("ASR_MODEL", "small")
ASR_DEVICE  = os.getenv("ASR_DEVICE", "cpu")            # cpu | cuda
ASR_COMPUTE = os.getenv("ASR_COMPUTE", "int8")          # int8 | int8_float32 | float16 | float32 (depends on your install)
ASR_LANG    = os.getenv("ASR_LANG", "en")
ASR_PORT    = int(os.getenv("ASR_PORT", "9000"))

@asynccontextmanager
async def lifespan(app: FastAPI):
    # load once at startup
    model = WhisperModel(
        ASR_MODEL,
        device=ASR_DEVICE,
        compute_type=ASR_COMPUTE
    )
    app.state.model = model
    try:
        yield
    finally:
        # free resources on shutdown (best-effort)
        try:
            del app.state.model
        except Exception:
            pass

app = FastAPI(title="Faster-Whisper Service", lifespan=lifespan)

@app.get("/health")
def health():
    return {"ok": True, "model": ASR_MODEL, "device": ASR_DEVICE, "compute": ASR_COMPUTE}

@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...)):
    if not audio or not audio.filename:
        raise HTTPException(status_code=400, detail="No audio file")

    # persist upload to a temp file
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
        elapsed_ms = int((time.time() - t0) * 1000)
        return JSONResponse({
            "text": text,
            "time_ms": elapsed_ms,
            "language": getattr(info, "language", None),
            "language_probability": getattr(info, "language_probability", None),
        })
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"ASR failed: {e}") from e
    finally:
        try:
            os.remove(tmp_path)
        except Exception:
            pass

if __name__ == "__main__":
    import uvicorn
    # supports: python server.py
    uvicorn.run("server:app", host="0.0.0.0", port=ASR_PORT, reload=True)
