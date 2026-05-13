# OpenEMR AI Visit Forms

> AI-powered voice dictation with local LLM formatting for OpenEMR encounter forms.  
> Supports **OpenEMR 5.0.1** and **OpenEMR 8.x**.  
> Developed by **Dr. D.A.C. Halliday** — Liamuiga Systems, Nassau, Bahamas.

> > 🛠️ **Developer Preview** — Planned with Claude (Anthropic) · Code generated with OpenAI Codex · Intended for developer review only · Use at your own risk · Not clinically validated

> ⚠️ **Disclaimer:** DISCLAIMER: This software is experimental and provided without warranty of any kind, express or implied. It was architected using Claude (Anthropic) and implemented using OpenAI Codex. It is intended solely for review and adaptation by qualified developers. The authors accept no liability for clinical, financial, or data-related outcomes arising from its use. Deployment in a live clinical or HIPAA-covered environment is undertaken entirely at the user's own risk.
---



## What This Does

This module adds a fully featured AI voice dictation form to OpenEMR encounters. The clinician dictates naturally — the local AI formats the transcript into structured clinical fields automatically.

```
Clinician speaks
      ↓
Speech-to-Text (Whisper / Deepgram / Chrome STT)
      ↓
Raw transcript
      ↓
Local LLM — ClinicalScribe (Ollama + Qwen2.5)
      ↓
Structured JSON — all fields populated
      ↓
OpenEMR encounter form fields filled
      ↓
Clinician reviews → saves to separate database
```

**No patient audio or data needs to leave your server** when using local STT and local LLM — full offline, air-gapped operation supported.

---

## Key Features

- **Three dictation modes** — Plain (free text), HPC (History of Presenting Complaint), SOAP
- **HPC mode** includes full clinical sections: Presenting Complaint → History → OB/GYN History → Examination (Vitals, General, Respiratory, CVS, Abdomen, Pelvis, CNS) → Assessment & Differential → Plan
- **SOAP mode** with tabbed S / O / A / P / Notes navigation
- **AI review overlay** — clinician approves output before fields are written
- **Generate Letter** — produces referral/discharge/results/follow-up letters from any dictation mode
- **Multiple STT engines** — local Whisper.cpp, Faster-Whisper, Deepgram Nova-3 Medical, Google Cloud STT, Chrome Web Speech
- **Multiple LLM providers** — Local Ollama (default, private), Claude API, Gemma Vertex AI, OpenAI
- **PHI scrubber** — built-in, always active before any cloud API call
- **Separate database** — dictation records stored in `periomed_dictation`, isolated from core OpenEMR tables
- **Persistent dictation** — background service worker keeps recording even when tab is minimised or switched
- **ClinicalScribe skill** — custom Ollama Modelfile with OB/GYN vocabulary, field mapping rules, and clinical formatting baked in

---

## Repository Structure

```
Openemr-AI-Visit-Forms/
├── files/
│   └── ai_visit_forms/           ← OpenEMR form files (copy to interface/forms/)
│       ├── index.php             ← Main encounter form
│       ├── save.php              ← POST handler → periomed_dictation DB
│       ├── format.php            ← AI formatting gateway caller
│       ├── transcribe.php        ← STT handler (Whisper / Deepgram / etc.)
│       ├── report.php            ← Read-only encounter summary
│       ├── table.sql             ← Database schema
│       ├── form_body.html        ← Form HTML (Plain / HPC / SOAP tabs)
│       ├── dictation.js          ← Recording, tab switching, field fill
│       ├── letter.js             ← Letter generation modal
│       ├── style.css             ← OpenEMR-matched form styling
│       └── common.php            ← Shared helpers and config reader
├── ollama-skill/
│   ├── Modelfile                 ← ClinicalScribe Ollama skill definition
│   ├── install.sh                ← One-command skill installer
│   ├── test_skill.sh             ← Validation test suite
│   └── README.md
├── setup-scripts/
│   ├── 00_run_all.sh             ← Full stack installer (run this on a fresh server)
│   ├── 01_install_ollama.sh      ← Ollama installer + systemd service
│   ├── 02_install_model.sh       ← LLM model pull + ClinicalScribe build
│   ├── 03_install_whisper.sh     ← Whisper.cpp build + systemd service
│   └── 04_install_fastapi.sh     ← FastAPI gateway installer + systemd service
├── local-stt-server/
│   ├── install_faster_whisper_server.sh
│   ├── start_faster_whisper_server.sh
│   └── requirements.txt
├── install_local_stt_fork.sh     ← OpenEMR form file installer
└── README.md
```

---

## Requirements

### Server
| Component | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 20.04 | Ubuntu 22.04 LTS |
| CPU | 4-core x86_64 | 8-core AMD EPYC / Intel Xeon |
| RAM | 8 GB | 16 GB |
| Disk | 20 GB free | 50 GB free |
| GPU | None (CPU-only works) | NVIDIA (optional, speeds inference 10×) |
| Swap | 4 GB recommended | 4 GB |

### Software
- OpenEMR 5.0.1 or 8.x
- PHP 7.x (5.0.1) or PHP 8.1+ (8.x)
- Python 3.10+
- Ollama 0.22+
- Apache / Nginx
- MariaDB / MySQL

---

## Quick Install — Fresh Server

```bash
# 1. Clone the repository
git clone https://github.com/dahalday/Openemr-AI-Visit-Forms.git
cd Openemr-AI-Visit-Forms

# 2. Run the full stack installer (as root)
sudo bash setup-scripts/00_run_all.sh
```

The installer auto-detects your RAM and selects the appropriate model:
- **16 GB** → `qwen2.5:14b-instruct-q4_K_M` (best quality, ~9.5 GB)
- **12 GB** → `qwen2.5:7b-instruct-q4_K_M` (~4.7 GB)
- **8 GB** → `qwen2.5:7b-instruct-q2_K` (test server, ~3.0 GB)

Then installs the OpenEMR form:

```bash
# For OpenEMR 5.0.1
sudo bash install_local_stt_fork.sh /path/to/openemr

# Example:
sudo bash install_local_stt_fork.sh /home/clinic/public_html/custom
```

---

## Manual Install — Step by Step

### Step 1 — Ollama

```bash
curl -fsSL https://ollama.ai/install.sh | sh
systemctl enable ollama
systemctl start ollama
```

### Step 2 — Pull LLM model and build ClinicalScribe

```bash
# Pull base model (choose based on your RAM)
ollama pull qwen2.5:14b-instruct-q4_K_M   # 16 GB server
ollama pull qwen2.5:7b-instruct-q4_K_M    # 12 GB server
ollama pull qwen2.5:7b-instruct-q2_K      # 8 GB test server

# Build ClinicalScribe skill
ollama create clinicalscribe -f ollama-skill/Modelfile

# Verify
ollama list
```

### Step 3 — Whisper.cpp STT server

```bash
apt install -y build-essential cmake git ffmpeg
cd /opt && git clone https://github.com/ggerganov/whisper.cpp
cd /opt/whisper.cpp
cmake -B build && cmake --build build --config Release -j$(nproc)

# Download model (choose based on RAM)
bash ./models/download-ggml-model.sh medium.en   # 16 GB — best accuracy
bash ./models/download-ggml-model.sh small.en    # 8 GB — lighter

# Install as systemd service
# (see setup-scripts/03_install_whisper.sh)
```

### Step 4 — FastAPI gateway

```bash
pip3 install fastapi uvicorn httpx pydantic
mkdir -p /opt/ai-dictation
# Copy main.py from setup-scripts/04_install_fastapi.sh
# Install as systemd service on port 11500
```

### Step 5 — Add swap (strongly recommended)

```bash
fallocate -l 4G /swapfile
chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

### Step 6 — Install OpenEMR form files

```bash
OEMR=/path/to/your/openemr
cp -r files/ai_visit_forms/ $OEMR/interface/forms/
```

Register the form in OpenEMR database:
```sql
INSERT INTO list_options (list_id, option_id, title, seq, is_default)
VALUES ('lbfnames', 'ai_visit_forms', 'AI Dictation', 99, 0);
```

---

## Configuration — Global Settings

Go to **Admin → Globals → Voice Dictation** and configure:

### LLM Provider
| Setting | Value |
|---|---|
| Active Provider | Local — Ollama |
| Ollama Endpoint | http://localhost:11434 |
| Ollama Model | Custom |
| Custom Model Name | `clinicalscribe` |

### Speech-to-Text
| Setting | Value |
|---|---|
| STT Engine | Whisper.cpp — Local server |
| Whisper Endpoint | http://localhost:9000 |
| Language | en-BS (English — Bahamas) |

### Key timeout settings in format.php
```php
// Must be raised for local 14B model on CPU
curl_setopt($ch, CURLOPT_TIMEOUT, 600);  // line ~446
set_time_limit(700);
```

---

## STT Engine Options

| Engine | Privacy | Accuracy | Cost | Setup |
|---|---|---|---|---|
| **Whisper.cpp local** | ✅ Full | ★★★☆ | Free | Included |
| **Faster-Whisper local** | ✅ Full | ★★★★ | Free | Python server |
| **Deepgram Nova-3 Medical** | ✅ BAA | ★★★★★ | ~$0.004/min | API key |
| Google Cloud STT v2 | ✅ BAA | ★★★★ | ~$0.012/min | API key |
| Chrome Web Speech | ❌ Google cloud | ★★☆ | Free | None |

**Deepgram Nova-3 Medical** is the recommended cloud option — purpose-built for clinical dictation, handles OB/GYN terminology, drug names, and dosing instructions better than any other STT engine. New accounts receive **$200 free credit** at [console.deepgram.com](https://console.deepgram.com).

---

## LLM Provider Options

| Provider | Privacy | Quality | Cost | Notes |
|---|---|---|---|---|
| **Ollama local (default)** | ✅ Full | ★★★★ | Free | Recommended |
| Claude API (Haiku 3.5) | ⚠️ PHI scrubbed | ★★★★★ | ~$0.003/encounter | BAA required |
| Gemma Vertex AI | ⚠️ PHI scrubbed | ★★★★ | Low / free tier | BAA on Vertex |
| OpenAI GPT-4o-mini | ⚠️ PHI scrubbed | ★★★★ | ~$0.002/encounter | No BAA |

PHI scrubber runs automatically before any cloud LLM call — strips patient name, DOB, MRN, and address from transcript.

---

## ClinicalScribe Skill

ClinicalScribe is a custom Ollama model built on Qwen2.5 with a clinical system prompt baked in. It:

- Returns **only valid JSON** — no preamble, no markdown
- Maps dictation to exact field keys (`hpc_pc`, `ex_vitals`, `soap_s`, etc.)
- Formats medications as `Drug name + dose + route + frequency + duration`
- Converts spoken obstetric language to standard notation (G2P2, LMP, NKDA, LSCS, CMT, βHCG, TVUSS)
- Formats vitals as a single pipe-separated line
- Orders examination findings in traditional clinical sequence
- Returns empty string `""` for fields not in the dictation — never invents data

### Update the skill

```bash
# Edit system prompt
nano /opt/ai-dictation/ollama-skill/Modelfile

# Rebuild
ollama rm clinicalscribe
ollama create clinicalscribe -f /opt/ai-dictation/ollama-skill/Modelfile

# Test
bash /opt/ai-dictation/ollama-skill/test_skill.sh
```

---

## Verify Installation

```bash
# All services running
systemctl is-active ollama whisper-server ai-dictation apache2 mariadb

# All ports listening
ss -tlnp | grep -E '9000|11434|11500|:80|:443'

# Gateway health
curl http://127.0.0.1:11500/health

# Full pipeline test
curl -s http://127.0.0.1:11500/format \
  -H "Content-Type: application/json" \
  -d '{
    "mode": "hpc",
    "transcript": "32 year old with lower abdominal pain 3 days, cramping 6 out of 10, LMP 2 weeks ago G2P2, BP 118/76 HR 88 temp 37.6, abdomen soft tender bilateral iliac fossa, no CMT, impression PID, doxycycline 100mg twice daily metronidazole 400mg three times daily 14 days"
  }' | python3 -m json.tool
```

Expected: all 22 HPC fields populated with clean clinical JSON.

---

## Known Issues & Fixes

### Dictation not holding — fields appear blank after Format with AI
**Cause:** `format.php` default timeout is 45 seconds — too short for local 14B model (needs 2–4 min on CPU).  
**Fix:**
```bash
sed -i '446s/CURLOPT_TIMEOUT, 45/CURLOPT_TIMEOUT, 600/' \
    $OEMR/interface/forms/ai_visit_forms/format.php
sed -i 's/set_time_limit(150)/set_time_limit(700)/' \
    $OEMR/interface/forms/ai_visit_forms/format.php
```

### Local AI unavailable warning — fallback to keyword draft
**Cause:** Model name in Global Settings doesn't match installed model.  
**Fix:** Set Custom Ollama Model Name to `clinicalscribe` in Admin → Globals → Voice Dictation.

### ffmpeg not found — audio conversion fails
**Fix:**
```bash
apt install -y ffmpeg
sed -i 's|"ffmpeg |"/usr/bin/ffmpeg |g' \
    $OEMR/interface/forms/ai_visit_forms/transcribe.php
```

### Chrome STT stops when tab is minimised
**Fix:** Switch STT Engine to Whisper.cpp local — audio is captured by the extension background service worker and sent to Whisper, independent of tab visibility.

### Server runs out of RAM during inference
**Fix:** Add swap and optionally stop Whisper temporarily during heavy LLM use:
```bash
fallocate -l 4G /swapfile && chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

---

## Hardware Recommendations

| Server | RAM | Model | Whisper | Response time |
|---|---|---|---|---|
| Test / dev | 8 GB | qwen2.5:7b Q2_K | small.en | 3–5 min |
| Small clinic | 16 GB | qwen2.5:14b Q4 | medium.en | 2–4 min |
| Production | 32 GB | qwen2.5:14b Q4 | medium.en | 1–2 min |
| GPU server | 16 GB + RTX | qwen2.5:14b Q4 | large-v3 | 5–15 sec |

Adding a single **NVIDIA RTX 3060 12GB (~$300)** drops response time from minutes to seconds. Ollama supports CUDA automatically — no code changes needed.

---

## Privacy & Compliance

- **Local mode:** Audio, transcript, and patient data never leave the server
- **Cloud LLM mode:** PHI scrubber removes name, DOB, MRN, address before transmission; re-associates on return
- **Cloud STT mode:** Audio sent to provider — use only Deepgram or Google with a signed BAA for HIPAA-covered entities
- **Database:** Dictation stored in `periomed_dictation` — separate from core OpenEMR tables, can be removed cleanly
- **Medicolegal:** Review overlay is mandatory before any field is written to the encounter record

---

## Background

Developed at **PerioMedCare, Nassau, Bahamas** by Dr. D.A.C. Halliday (Consultant Gynaecologic Oncologist) in response to the OpenEMR community's long-running need for embedded, privacy-respecting voice dictation:

- [Voice-to-Text in OpenEMR: What's Possible Today? (2025)](https://community.open-emr.org/t/voice-to-text-in-openemr-what-s-possible-today/25448)
- [Voice to Text with AI revision assistance](https://community.open-emr.org/t/voice-to-text-with-ai-revision-assistance/24222)
- [Speech dictation thread (2017–2025)](https://community.open-emr.org/t/speech-dictation/8670)

Built with: Ollama, Qwen2.5, Whisper.cpp, Faster-Whisper, FastAPI, Deepgram Nova-3 Medical, OpenEMR 5.0.1 / 8.x.

---

> *Planned with Claude (Anthropic) · Code generated with OpenAI Codex · Developer review intended · Use at own risk · No clinical warranty expressed or implied.*

---

## License

[Apache License 2.0](LICENSE)

---

## Contributing

Pull requests welcome. Please open an issue first for significant changes.  
For OpenEMR questions: [community.open-emr.org](https://community.open-emr.org)  
For project questions: open a GitHub issue.

---

*Liamuiga Systems — Nassau, Bahamas*
