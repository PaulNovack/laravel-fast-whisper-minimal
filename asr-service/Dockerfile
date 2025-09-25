FROM python:3.11-slim

# Opus/WebM decoding needs ffmpeg
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Optional CPU torch (usually not needed for faster-whisper on CPU)
# RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu

COPY server.py .

ENV ASR_MODEL=small \
    ASR_DEVICE=cpu \
    ASR_COMPUTE=int8 \
    ASR_LANG=en

EXPOSE 9000
CMD ["uvicorn", "server:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
