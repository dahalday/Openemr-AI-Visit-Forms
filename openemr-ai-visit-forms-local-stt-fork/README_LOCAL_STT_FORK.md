# OpenEMR AI Visit Forms Local STT Fork

This fork adds hardware-aware local transcription options to Advance Visit Form.

New STT engines:

- `deepgram_nova3_medical`
- `faster_whisper_auto`
- `faster_whisper_cpu`
- `faster_whisper_gpu`
- `faster_whisper_max`

Recommended default:

```text
faster-whisper - Auto
model: large-v3-turbo
endpoint: http://127.0.0.1:9010
```

Cloud medical dictation option:

```text
STT Engine: Deepgram Nova-3 Medical
Deepgram Model: nova-3-medical
Deepgram Smart Format: enabled
Deepgram Dictation Mode: enabled
Deepgram Measurements: enabled
```

Enter the Deepgram API key in:

```text
Administration > Globals > Voice Dictation > Deepgram API Key
```

The form sends comma-separated `Deepgram Medical Keyterms` to Deepgram keyterm prompting. Keep that list short and specific to your clinic vocabulary; Deepgram supports up to 100 keyterms.

Install into the comparison clone:

```bash
bash install_local_stt_fork.sh /var/www/html/clinic
```

Install/start the local server:

```bash
cd local-stt-server
bash install_faster_whisper_server.sh
bash start_faster_whisper_server.sh
```

Hardware recommendation:

| Hardware | Recommended option |
| --- | --- |
| 8-core CPU, 16-32 GB RAM | faster-whisper CPU, large-v3-turbo, int8 |
| 16-24 cores, 64 GB RAM | faster-whisper CPU, large-v3-turbo, int8 |
| RTX 3060/4060/4070, 12 GB VRAM | faster-whisper GPU, large-v3-turbo, float16 |
| RTX 3090/4090, 24 GB VRAM | faster-whisper max, large-v3 or large-v3-turbo |
| A4000/A5000/A6000 | faster-whisper GPU, large-v3-turbo or large-v3 |
