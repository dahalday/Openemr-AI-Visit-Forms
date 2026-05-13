# Deepgram Nova-3 Medical STT

This package adds Deepgram as a cloud dictation engine beside Chrome, Google Cloud Speech-to-Text, Whisper.cpp, and faster-whisper.

## OpenEMR Setting

Open:

```text
Administration > Config > Voice Dictation
```

Set:

```text
STT Engine: Deepgram Nova-3 Medical
Deepgram Model: nova-3-medical
Deepgram Smart Format: Yes
Deepgram Dictation Mode: Yes
Deepgram Measurements: Yes
```

Enter your key in:

```text
Deepgram API Key
```

Do not put the API key in this package or in shell history. If a key was shared in chat or screenshots, rotate it in the Deepgram console.

## Medical Vocabulary

Use `Deepgram Medical Keyterms` for short comma-separated medical terms that are commonly spoken in your clinic.

Example:

```text
pelvic inflammatory disease, tubo-ovarian abscess, cervical motion tenderness, adnexal tenderness, CA-125, labia majora
```

The package sends these terms as Deepgram keyterm prompts and limits the list to 100 terms.

## Server Requirement

Install `ffmpeg` because the browser records WebM/OGG and the OpenEMR endpoint converts it to WAV before sending it to Deepgram:

```bash
apt update
apt install -y ffmpeg
```
