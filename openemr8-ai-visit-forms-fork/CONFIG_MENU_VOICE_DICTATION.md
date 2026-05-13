# Voice Dictation Config Menu Notes

OpenEMR 8 shows custom global settings through the metadata array in:

```text
library/globals.inc.php
```

This package patches that file with:

```php
$GLOBALS_METADATA['Voice Dictation'] = [...]
```

Run:

```bash
php scripts/ensure_openemr8_voice_dictation_config.php /path/to/openemr
```

Then log out/in and open:

```text
Administration > Config > Voice Dictation
```

The tab includes all current and future options:

- Local Ollama
- GPT API - OpenAI
- Claude API - Anthropic
- Gemma / Gemini - Vertex AI
- Chrome Web Speech API
- Google Cloud Speech-to-Text
- Deepgram Nova-3 Medical
- Whisper.cpp local server
- faster-whisper auto / CPU / GPU / max

Deepgram fields included:

- Deepgram API Key
- Deepgram Model
- Custom Deepgram Model
- Deepgram Medical Keyterms
- Deepgram Smart Format
- Deepgram Dictation Mode
- Deepgram Measurements

The script creates a timestamped backup:

```text
library/globals.inc.php.bak-openemr8-ai-voice-YYYYmmdd-HHMMSS
```

If the tab does not appear after patching:

1. Run `php -l library/globals.inc.php`.
2. Restart Apache/PHP-FPM.
3. Clear browser cache or log out/in.
4. Confirm the file contains `Voice Dictation`.
