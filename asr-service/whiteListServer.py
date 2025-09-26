# asr-service/server.py
import os, time, tempfile, json, re
from contextlib import asynccontextmanager
from typing import List, Optional, Tuple

from fastapi import FastAPI, File, Form, UploadFile, HTTPException, Body
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from faster_whisper import WhisperModel

ASR_MODEL   = os.getenv("ASR_MODEL", "small")
ASR_DEVICE  = os.getenv("ASR_DEVICE", "cpu")            # cpu | cuda
ASR_COMPUTE = os.getenv("ASR_COMPUTE", "int8")          # int8 | int8_float32 | float16 | float32
ASR_LANG    = os.getenv("ASR_LANG", "en")
ASR_PORT    = int(os.getenv("ASR_PORT", "9000"))
WORDS_FILE  = os.getenv("ALLOWED_WORDS_FILE")           # optional: /app/words.txt

# ----------------------------
# Helpers: word list + mapping
# ----------------------------
def _normalize_term(t: str) -> Optional[str]:
    if not t:
        return None
    # Trim, collapse spaces, keep punctuation inside phrases (e.g., "ice cream", "O'Connor")
    s = " ".join(t.strip().split())
    if not s or s.startswith("#"):  # allow comments in file
        return None
    return s

def _load_words_from_text(text: str) -> List[str]:
    words = []
    for line in text.splitlines():
        s = _normalize_term(line)
        if s:
            words.append(s)
    # De-dupe preserving order (case-insensitive)
    seen = set()
    deduped = []
    for w in words:
        key = w.lower()
        if key not in seen:
            seen.add(key)
            deduped.append(w)
    return deduped

def _load_words_from_file(path: str) -> List[str]:
    with open(path, "r", encoding="utf-8") as f:
        return _load_words_from_text(f.read())

def _best_match(word: str, vocab: List[str]) -> Tuple[Optional[str], float]:
    """
    Fuzzy match by normalized Levenshtein ratio (SequenceMatcher).
    """
    from difflib import SequenceMatcher
    wl = word.lower()
    best, score = None, 0.0
    for v in vocab:
        s = SequenceMatcher(None, wl, v.lower()).ratio()
        if s > score:
            best, score = v, s
    return best, score

def whitelist_map(text: str, vocab: List[str], strict: bool = True, threshold: float = 0.72, unk_token: str = "<?>") -> str:
    """
    Map arbitrary text to nearest items from vocab. If strict, replace unknowns with unk_token.
    Tokenization: simple whitespace split + inner punctuation kept; you can swap for a smarter tokenizer if needed.
    """
    if not vocab:
        return text
    out = []
    # Split on whitespace but keep words with apostrophes/hyphens; strip outer punctuation
    for raw in text.split():
        w = raw.strip(".,!?;:\"()[]{}")
        if not w:
            continue
        m, s = _best_match(w, vocab)
        if m and s >= threshold:
            out.append(m)
        elif strict:
            out.append(unk_token)
        else:
            out.append(w)
    return " ".join(out)

def build_initial_prompt(vocab: List[str], max_chars: int = 2000) -> str:
    """
    Join vocab into a space-separated prompt but cap length to avoid huge prompts.
    """
    if not vocab:
        return ""
    acc, total = [], 0
    for w in vocab:
        if total + len(w) + 1 > max_chars:
            break
        acc.append(w)
        total += len(w) + 1
    return " ".join(acc)

# ----------------------------
# FastAPI app + lifecycle
# ----------------------------
class WordsPayload(BaseModel):
    words: List[str]

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Model
    model = WhisperModel(
        ASR_MODEL,
        device=ASR_DEVICE,
        compute_type=ASR_COMPUTE
    )
    app.state.model = model

    # Allowed words (optional, from file)
    server_words: List[str] = []
    if WORDS_FILE and os.path.exists(WORDS_FILE):
        try:
            server_words = _load_words_from_file(WORDS_FILE)
        except Exception as e:
            print(f"[WARN] Failed to load ALLOWED_WORDS_FILE='{WORDS_FILE}': {e}")
    app.state.allowed_words = server_words

    try:
        yield
    finally:
        try:
            del app.state.model
        except Exception:
            pass

app = FastAPI(title="Faster-Whisper Service", lifespan=lifespan)

# ----------------------------
# Health / config endpoints
# ----------------------------
@app.get("/health")
def health():
    return {
        "ok": True, "model": ASR_MODEL, "device": ASR_DEVICE, "compute": ASR_COMPUTE,
        "lang": ASR_LANG, "words_loaded": len(getattr(app.state, "allowed_words", []))
    }

@app.get("/config/words")
def get_words():
    return {"words": getattr(app.state, "allowed_words", []), "count": len(getattr(app.state, "allowed_words", []))}

@app.put("/config/words")
async def put_words(
    words_json: Optional[str] = Form(default=None, description='JSON array string, e.g. ["start","stop"]'),
    file: Optional[UploadFile] = File(default=None, description="Plain text file, one term per line")
):
    """
    Update the server-wide allowed word list.
    Use either:
      - multipart/form-data with a 'file', OR
      - multipart/form-data with 'words_json' (a JSON array string).
    """
    if not words_json and not file:
        raise HTTPException(status_code=400, detail="Provide 'words_json' or upload a text 'file'.")

    words: List[str] = []
    if words_json:
        try:
            data = json.loads(words_json)
            if not isinstance(data, list):
                raise ValueError("words_json must be a JSON array")
            words = []
            for t in data:
                s = _normalize_term(str(t))
                if s:
                    words.append(s)
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Invalid words_json: {e}")

    if file:
        try:
            content = (await file.read()).decode("utf-8", errors="ignore")
            words_from_file = _load_words_from_text(content)
            words.extend(words_from_file)
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Failed reading file: {e}")

    # Deduplicate case-insensitively, preserving order
    seen = set()
    dedup = []
    for w in words:
        k = w.lower()
        if k not in seen:
            seen.add(k)
            dedup.append(w)

    app.state.allowed_words = dedup
    return {"ok": True, "count": len(dedup)}

# ----------------------------
# Transcribe endpoint
# ----------------------------
@app.post("/transcribe")
async def transcribe(
    audio: UploadFile = File(...),
    # optional per-request words (either JSON array string OR text file)
    words_json: Optional[str] = Form(default=None),
    words_file: Optional[UploadFile] = File(default=None),
    # mapping knobs
    strict: bool = Form(default=True),
    threshold: float = Form(default=0.72),
    # decoding knobs
    beam_size: int = Form(default=5),
    temperature: float = Form(default=0.0),
    vad_filter: bool = Form(default=True),
    word_timestamps: bool = Form(default=False)
):
    """
    Submit audio plus optional word list (JSON or text file).
    If no per-request words are given, the server's configured list is used.
    Returns both raw and mapped transcripts.
    """
    if not audio or not audio.filename:
        raise HTTPException(status_code=400, detail="No audio file")

    # Build the effective vocabulary
    effective_words: List[str] = []
    if words_json:
        try:
            data = json.loads(words_json)
            if not isinstance(data, list):
                raise ValueError("words_json must be a JSON array")
            for t in data:
                s = _normalize_term(str(t))
                if s:
                    effective_words.append(s)
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Invalid words_json: {e}")

    if words_file:
        try:
            content = (await words_file.read()).decode("utf-8", errors="ignore")
            effective_words.extend(_load_words_from_text(content))
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Failed reading words_file: {e}")

    if not effective_words:
        effective_words = getattr(app.state, "allowed_words", [])

    # Persist upload to a temp file
    with tempfile.NamedTemporaryFile(suffix=f"_{os.path.basename(audio.filename)}", delete=False) as tmp:
        tmp.write(await audio.read())
        tmp_path = tmp.name

    t0 = time.time()
    try:
        initial_prompt = build_initial_prompt(effective_words)
        segments, info = app.state.model.transcribe(
            tmp_path,
            language=ASR_LANG,
            vad_filter=vad_filter,
            temperature=temperature,
            beam_size=beam_size,
            word_timestamps=word_timestamps,
            initial_prompt=initial_prompt if initial_prompt else None
        )
        raw_text = "".join(s.text for s in segments).strip()

        # map to whitelist if provided
        mapped_text = whitelist_map(raw_text, effective_words, strict=strict, threshold=threshold) if effective_words else raw_text

        elapsed_ms = int((time.time() - t0) * 1000)
        return JSONResponse({
            "raw_text": raw_text,
            "text": mapped_text,          # <-- final, mapped text
            "time_ms": elapsed_ms,
            "language": getattr(info, "language", None),
            "language_probability": getattr(info, "language_probability", None),
            "used_words_count": len(effective_words)
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
    uvicorn.run("server:app", host="0.0.0.0", port=ASR_PORT, reload=True)
