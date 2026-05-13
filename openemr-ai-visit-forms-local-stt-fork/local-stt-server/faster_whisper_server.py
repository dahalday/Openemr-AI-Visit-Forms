#!/usr/bin/env python3
"""Local faster-whisper server for OpenEMR AI Visit Forms."""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import tempfile
import time
from functools import lru_cache
from typing import Dict, Tuple

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

try:
    from faster_whisper import WhisperModel
except Exception as exc:  # pragma: no cover - useful startup error
    WhisperModel = None
    IMPORT_ERROR = str(exc)
else:
    IMPORT_ERROR = ""


APP = FastAPI(title="OpenEMR AI Visit Forms faster-whisper STT")
MODEL_CACHE: Dict[Tuple[str, str, str], WhisperModel] = {}


def run_capture(command):
    try:
        return subprocess.check_output(command, stderr=subprocess.DEVNULL, text=True).strip()
    except Exception:
        return ""


def cpu_count() -> int:
    return os.cpu_count() or 1


def mem_gb() -> float:
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("MemTotal:"):
                    kb = int(line.split()[1])
                    return round(kb / 1024 / 1024, 1)
    except Exception:
        pass
    return 0.0


def nvidia_info():
    if not shutil.which("nvidia-smi"):
        return []
    output = run_capture([
        "nvidia-smi",
        "--query-gpu=name,memory.total",
        "--format=csv,noheader,nounits",
    ])
    gpus = []
    for line in output.splitlines():
        parts = [part.strip() for part in line.split(",")]
        if len(parts) >= 2:
            try:
                vram_gb = round(float(parts[1]) / 1024, 1)
            except ValueError:
                vram_gb = 0.0
            gpus.append({"name": parts[0], "vram_gb": vram_gb})
    return gpus


def recommendation():
    cores = cpu_count()
    ram = mem_gb()
    gpus = nvidia_info()
    best_vram = max([gpu["vram_gb"] for gpu in gpus], default=0.0)

    if best_vram >= 20:
        return {
            "tier": "strong_gpu",
            "engine": "faster_whisper_gpu",
            "model": "large-v3",
            "device": "cuda",
            "compute_type": "float16",
            "reason": "24 GB class GPU is suitable for full large-v3 or large-v3-turbo.",
        }
    if best_vram >= 10:
        return {
            "tier": "best_value_gpu",
            "engine": "faster_whisper_gpu",
            "model": "large-v3-turbo",
            "device": "cuda",
            "compute_type": "float16",
            "reason": "12 GB class NVIDIA GPU is ideal for large-v3-turbo.",
        }
    if cores >= 16 and ram >= 48:
        return {
            "tier": "good_cpu",
            "engine": "faster_whisper_cpu",
            "model": "large-v3-turbo",
            "device": "cpu",
            "compute_type": "int8",
            "reason": "16+ cores and high RAM can run large-v3-turbo on CPU.",
        }
    if cores >= 8 and ram >= 15:
        return {
            "tier": "minimum_cpu",
            "engine": "faster_whisper_cpu",
            "model": "large-v3-turbo",
            "device": "cpu",
            "compute_type": "int8",
            "reason": "CPU-only dictation should work, but expect slower chunks.",
        }
    return {
        "tier": "low_resource",
        "engine": "whisper_cpp",
        "model": "medium",
        "device": "cpu",
        "compute_type": "int8",
        "reason": "Hardware is below the recommended faster-whisper baseline.",
    }


@lru_cache(maxsize=1)
def capabilities():
    return {
        "cpu_cores": cpu_count(),
        "ram_gb": mem_gb(),
        "gpus": nvidia_info(),
        "ffmpeg": bool(shutil.which("ffmpeg")),
        "faster_whisper_import_ok": WhisperModel is not None,
        "faster_whisper_import_error": IMPORT_ERROR,
        "recommendation": recommendation(),
    }


def normalize_language(language: str) -> str:
    value = (language or "").strip()
    if not value:
        return "en"
    return value.split("-")[0].lower()


def resolve_model(model: str, preset: str) -> str:
    model = (model or "").strip()
    if model in ("", "auto"):
        model = "large-v3-turbo"
    if preset == "max_accuracy":
        model = "large-v3"
    return model


def resolve_device(device: str, preset: str) -> str:
    device = (device or "auto").strip().lower()
    if preset == "cpu":
        return "cpu"
    if preset == "gpu":
        return "cuda"
    if device in ("cpu", "cuda"):
        return device
    return "cuda" if nvidia_info() else "cpu"


def resolve_compute(compute_type: str, device: str, preset: str) -> str:
    compute_type = (compute_type or "auto").strip().lower()
    if compute_type != "auto":
        return compute_type
    if device == "cuda":
        return "float16"
    return "int8"


def get_model(model_name: str, device: str, compute_type: str):
    if WhisperModel is None:
        raise RuntimeError("faster-whisper is not installed: " + IMPORT_ERROR)
    key = (model_name, device, compute_type)
    if key not in MODEL_CACHE:
        MODEL_CACHE[key] = WhisperModel(model_name, device=device, compute_type=compute_type)
    return MODEL_CACHE[key]


@APP.get("/health")
def health():
    info = capabilities()
    status = "ok" if info["ffmpeg"] and info["faster_whisper_import_ok"] else "degraded"
    return {"status": status, **info}


@APP.get("/capabilities")
def get_capabilities():
    return capabilities()


@APP.post("/transcribe")
async def transcribe(
    file: UploadFile = File(...),
    preset: str = Form("auto"),
    model: str = Form("large-v3-turbo"),
    device: str = Form("auto"),
    compute_type: str = Form("auto"),
    language: str = Form("en"),
    vad_filter: str = Form("1"),
):
    start = time.time()
    preset = (preset or "auto").strip().lower()
    model_name = resolve_model(model, preset)
    resolved_device = resolve_device(device, preset)
    resolved_compute = resolve_compute(compute_type, resolved_device, preset)
    lang = normalize_language(language)
    use_vad = str(vad_filter).lower() not in ("0", "false", "no")

    suffix = os.path.splitext(file.filename or "audio.wav")[1] or ".wav"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as temp:
        temp.write(await file.read())
        temp_path = temp.name

    try:
        whisper = get_model(model_name, resolved_device, resolved_compute)
        segments, info = whisper.transcribe(
            temp_path,
            language=lang,
            vad_filter=use_vad,
            beam_size=1,
            temperature=0.0,
        )
        text = " ".join(segment.text.strip() for segment in segments if segment.text.strip()).strip()
        return {
            "text": text,
            "model": model_name,
            "device": resolved_device,
            "compute_type": resolved_compute,
            "language": getattr(info, "language", lang),
            "duration_seconds": round(time.time() - start, 3),
        }
    except Exception as exc:
        return JSONResponse({"error": str(exc)}, status_code=500)
    finally:
        try:
            os.unlink(temp_path)
        except OSError:
            pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default=os.getenv("FASTER_WHISPER_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.getenv("FASTER_WHISPER_PORT", "9010")))
    args = parser.parse_args()

    import uvicorn

    uvicorn.run(APP, host=args.host, port=args.port)


if __name__ == "__main__":
    main()
