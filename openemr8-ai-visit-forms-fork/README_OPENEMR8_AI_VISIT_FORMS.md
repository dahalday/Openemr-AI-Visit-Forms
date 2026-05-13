# OpenEMR 8 Advance Visit Form

This package installs the Advance Visit Form into an OpenEMR 8 webroot and adds the complete Voice Dictation configuration tab to **Administration > Config**.

It is designed for OpenEMR 8.x layouts where global configuration metadata is stored in:

```text
library/globals.inc.php
```

## What It Adds

- Encounter form: `interface/forms/ai_visit_forms`
- Form name in OpenEMR: `Advance Visit Form`
- Config tab: `Voice Dictation`
- LLM formatting options:
  - Local Ollama
  - GPT API - OpenAI
  - Claude API - Anthropic
  - Gemma / Gemini - Vertex AI
- Dictation / STT options:
  - Chrome Web Speech API
  - Google Cloud Speech-to-Text
  - Deepgram Nova-3 Medical
  - Whisper.cpp local server
  - faster-whisper auto / CPU / GPU / max
- Future local hardware options are visible immediately so the config does not need to be patched again after upgrade.

## Install On Another OpenEMR 8 Server

Upload and unzip:

```bash
cd /home/YOURUSER
unzip openemr8-ai-visit-forms-fork.zip
cd openemr8-ai-visit-forms-fork
```

Confirm the OpenEMR root:

```bash
OPENEMR_ROOT=/path/to/openemr
test -f "$OPENEMR_ROOT/interface/globals.php" && echo "OpenEMR root OK"
test -f "$OPENEMR_ROOT/sites/default/sqlconf.php" && echo "Database config OK"
```

Back up first:

```bash
tar -czf ai-visit-openemr8-backup-$(date +%Y%m%d-%H%M%S).tar.gz \
  "$OPENEMR_ROOT/interface/forms" \
  "$OPENEMR_ROOT/library/globals.inc.php" \
  "$OPENEMR_ROOT/sites/default/sqlconf.php"
```

Run installer:

```bash
bash install_openemr8_ai_visit_forms.sh "$OPENEMR_ROOT"
```

Fix ownership if the server uses a Virtualmin/cPanel domain user:

```bash
chown -R YOURUSER:YOURUSER "$OPENEMR_ROOT/interface/forms/ai_visit_forms"
chown YOURUSER:YOURUSER "$OPENEMR_ROOT/library/globals.inc.php"
```

Verify:

```bash
php -l "$OPENEMR_ROOT/library/globals.inc.php"
php -l "$OPENEMR_ROOT/interface/forms/ai_visit_forms/common.php"
php -l "$OPENEMR_ROOT/interface/forms/ai_visit_forms/format.php"
php -l "$OPENEMR_ROOT/interface/forms/ai_visit_forms/transcribe.php"
```

Check registration:

```bash
php -r '$root=getenv("OPENEMR_ROOT"); include "$root/sites/default/sqlconf.php"; $m=new mysqli($host,$login,$pass,$dbase,(int)$port); echo "DB OK: $dbase\n"; $r=$m->query("select name,directory,state,sql_run from registry where directory=\"ai_visit_forms\""); while($row=$r->fetch_assoc()) echo json_encode($row).PHP_EOL;'
```

Expected:

```text
{"name":"Advance Visit Form","directory":"ai_visit_forms","state":"1","sql_run":"1"}
```

Restart web services or log out/in:

```bash
systemctl restart apache2
systemctl restart php*-fpm 2>/dev/null || true
```

Then open:

```text
Administration > Config > Voice Dictation
```

## Recommended Initial Settings For Limited Servers

Use these until the hardware is upgraded:

- STT Engine: `Chrome Web Speech API`
- Active Provider: `GPT API - OpenAI` or `Gemma / Gemini - Vertex AI`
- OpenAI GPT Model: `gpt-4o-mini`
- Google AI Model: `gemini-2.5-flash`

If using Google Cloud Speech-to-Text, install `ffmpeg` because the server must convert browser audio before sending it to Google:

```bash
apt update
apt install -y ffmpeg
```

For medical cloud dictation, use:

- STT Engine: `Deepgram Nova-3 Medical`
- Deepgram Model: `nova-3-medical`
- Deepgram Smart Format: enabled
- Deepgram Dictation Mode: enabled
- Deepgram Measurements: enabled

Enter the key in:

```text
Administration > Config > Voice Dictation > Deepgram API Key
```

The `Deepgram Medical Keyterms` field is sent as keyterm prompting. Keep it focused on terms your clinicians actually dictate.

## Future Local Settings After Hardware Upgrade

Once Whisper/Ollama are installed and healthy:

- STT Engine: `faster-whisper - Auto hardware choice`
- faster-whisper Endpoint: `http://127.0.0.1:9010`
- faster-whisper Model: `large-v3-turbo`
- Active Provider: `Local Ollama`
- Ollama Endpoint: `http://localhost:11434`
- Ollama Model: `custom`
- Custom Ollama Model Name: `clinicalscribe`

## Config Menu Patch Only

To refresh only the Voice Dictation tab in OpenEMR 8:

```bash
php scripts/ensure_openemr8_voice_dictation_config.php "$OPENEMR_ROOT"
```

This creates a backup of `library/globals.inc.php` before changing it.
