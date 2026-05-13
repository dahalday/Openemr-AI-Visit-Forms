# Local Whisper / faster-whisper / Ollama Setup For OpenEMR 8

Use this after the server hardware is upgraded. On limited VPS hardware, keep Chrome, Google Cloud STT, or Deepgram Nova-3 Medical STT and GPT/Gemini/Claude formatting.

## Hardware Guide

Minimum CPU-only:

- 8 modern CPU cores
- 16-32 GB RAM
- Use faster-whisper CPU, `large-v3-turbo`, `int8`

Good CPU-only:

- 16-24 modern CPU cores
- 64 GB RAM
- Use faster-whisper CPU, `large-v3-turbo`, `int8`

Best GPU value:

- NVIDIA RTX 4060 Ti 16 GB, RTX 4070 12 GB, or similar
- 32-64 GB system RAM
- Use faster-whisper GPU, `large-v3-turbo`, `float16`

Strong GPU:

- RTX 3090/4090 24 GB or NVIDIA A4000/A5000/A6000
- Use `large-v3-turbo` for speed or `large-v3` for maximum accuracy

If `ctranslate2` fails with `Illegal instruction`, the CPU cannot run the faster-whisper wheel. Use Chrome, Google Cloud STT, Deepgram Nova-3 Medical, or whisper.cpp until the hardware is upgraded.

## Install faster-whisper Server

```bash
apt update
apt install -y ffmpeg python3-venv python3-pip

cd /home/YOURUSER/openemr8-ai-visit-forms-fork/local-stt-server
rm -rf .venv
bash install_faster_whisper_server.sh
```

Create the service:

```bash
ln -sfn /home/YOURUSER/openemr8-ai-visit-forms-fork /opt/openemr-ai-visit-forms-local-stt

cp faster-whisper-openemr.service.example /etc/systemd/system/openemr-faster-whisper.service
sed -i 's/User=adminuser/User=YOURUSER/' /etc/systemd/system/openemr-faster-whisper.service

systemctl daemon-reload
systemctl enable --now openemr-faster-whisper.service
```

Test:

```bash
curl http://127.0.0.1:9010/health
curl http://127.0.0.1:9010/capabilities
```

Expected:

```text
"status":"ok"
```

## Install Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sh
systemctl enable --now ollama
curl http://127.0.0.1:11434/api/tags
```

Pull a base model:

```bash
ollama pull qwen2.5:7b-instruct-q4_K_M
```

Create `clinicalscribe` if you have the Modelfile:

```bash
cd /home/YOURUSER/ollama-ai-visit-skill
ollama create clinicalscribe -f Modelfile
ollama list
```

Test:

```bash
curl -s http://127.0.0.1:11434/api/generate \
  -d '{"model":"clinicalscribe","prompt":"Reply only OK","stream":false}'
```

## OpenEMR 8 Config

Go to:

```text
Administration > Config > Voice Dictation
```

Set:

- STT Engine: `faster-whisper - Auto hardware choice`
- faster-whisper Endpoint: `http://127.0.0.1:9010`
- faster-whisper Model: `large-v3-turbo`
- faster-whisper Device: `auto`
- faster-whisper Compute Type: `auto`
- Active Provider: `Local Ollama`
- Ollama Endpoint: `http://localhost:11434`
- Ollama Model: `custom`
- Custom Ollama Model Name: `clinicalscribe`

## Troubleshooting

Service status:

```bash
systemctl status openemr-faster-whisper.service --no-pager
journalctl -u openemr-faster-whisper.service --no-pager -n 100
```

Python import check:

```bash
cd /home/YOURUSER/openemr8-ai-visit-forms-fork/local-stt-server
sudo -u YOURUSER .venv/bin/python -X faulthandler -c 'import ctranslate2; print("ctranslate2 ok")'
sudo -u YOURUSER .venv/bin/python -X faulthandler -c 'from faster_whisper import WhisperModel; print("faster-whisper ok")'
```

Ollama status:

```bash
systemctl status ollama --no-pager
ollama list
curl http://127.0.0.1:11434/api/tags
```
