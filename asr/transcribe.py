# asr/transcribe.py
import argparse, json, sys, os
from faster_whisper import WhisperModel

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", required=True)
    ap.add_argument("--model", default="small")   # tiny/base/small/medium/large-v3
    ap.add_argument("--language", default="en")
    args = ap.parse_args()

    # You can set device="cpu" or "cuda" depending on your box
    model = WhisperModel(args.model, device=os.environ.get("ASR_DEVICE", "cpu"), compute_type=os.environ.get("ASR_COMPUTE", "int8"))
    segments, info = model.transcribe(args.file, language=args.language, vad_filter=True)

    text = "".join(seg.text for seg in segments).strip()
    print(json.dumps({"text": text}, ensure_ascii=False))
    sys.exit(0)

if __name__ == "__main__":
    main()
