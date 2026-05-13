# faster-whisper Local STT Server

This server receives browser audio chunks from OpenEMR AI Visit Forms and transcribes them locally with `faster-whisper`.

Recommended model:

```text
large-v3-turbo
```

Endpoints:

```text
GET  http://127.0.0.1:9010/health
GET  http://127.0.0.1:9010/capabilities
POST http://127.0.0.1:9010/transcribe
```

Install:

```bash
cd local-stt-server
bash install_faster_whisper_server.sh
```

Start:

```bash
bash start_faster_whisper_server.sh
```

OpenEMR Global Settings:

```text
STT Engine: faster-whisper - Auto
faster-whisper Endpoint: http://127.0.0.1:9010
faster-whisper Model: large-v3-turbo
faster-whisper Device: auto
faster-whisper Compute Type: auto
```

Hardware recommendations:

| Setup | Hardware | Recommended mode |
| --- | --- | --- |
| Minimum CPU-only | 8-core CPU, 16-32 GB RAM | faster-whisper CPU, large-v3-turbo, int8 |
| Good CPU-only | 16-24 cores, 64 GB RAM | faster-whisper CPU, large-v3-turbo, int8 |
| Best value GPU | NVIDIA RTX 3060/4060/4070, 12 GB VRAM | faster-whisper GPU, large-v3-turbo, float16 |
| Strong GPU | RTX 3090/4090, 24 GB VRAM | faster-whisper GPU or max accuracy, large-v3 |
| Server GPU | NVIDIA A4000/A5000/A6000 | faster-whisper GPU, large-v3-turbo or large-v3 |

