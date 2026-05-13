<?php
/**
 * Ensure OpenEMR 8 shows the complete Voice Dictation tab in Admin > Config.
 */

$root = $argv[1] ?? '/var/www/html/openemr8';
$file = rtrim($root, '/') . '/library/globals.inc.php';
if (!is_file($file)) {
    fwrite(STDERR, "globals.inc.php not found: " . $file . PHP_EOL);
    exit(1);
}

$text = file_get_contents($file);
$backup = $file . '.bak-openemr8-ai-voice-' . date('Ymd-His');
copy($file, $backup);

$block = <<<'PHPBLOCK'
    $GLOBALS_METADATA['Voice Dictation'] = [
        'ai_dictation_section_general' => [
            xl('General - Voice Dictation Module'),
            'section_header',
            '',
            xl('Voice Dictation Module general settings.')
        ],
        'ai_dictation_enabled' => [
            xl('Enable Voice Dictation'),
            'bool',
            '1',
            xl('Activate the AI dictation tab within encounter forms.')
        ],
        'ai_dictation_default_mode' => [
            xl('Default Dictation Mode'),
            [
                'hpc' => xl('HPC - History of Presenting Complaint'),
                'soap' => xl('SOAP - Subjective / Objective / Assessment / Plan'),
                'plain' => xl('Plain Dictation'),
            ],
            'hpc',
            xl('Default note mode when opening the dictation form.')
        ],
        'ai_dictation_specialty_template' => [
            xl('Specialty Template'),
            [
                'obgyn' => xl('OB/GYN'),
                'general' => xl('General Medicine'),
                'emergency' => xl('Emergency Medicine'),
                'custom' => xl('Custom'),
            ],
            'obgyn',
            xl('Default clinical vocabulary/template for formatting.')
        ],
        'ai_dictation_auto_populate' => [
            xl('Auto-populate Fields After Formatting'),
            'bool',
            '1',
            xl('Automatically place AI formatted output into note fields after review.')
        ],
        'ai_dictation_require_review' => [
            xl('Require Review Before Save'),
            'bool',
            '1',
            xl('Require clinician review before formatted content is saved.')
        ],
        'ai_dictation_database' => [
            xl('Dictation Database'),
            'text',
            'periomed_dictation',
            xl('Optional external dictation database name.')
        ],
        'ai_dictation_section_phi' => [
            xl('PHI Scrubbing'),
            'section_header',
            '',
            xl('Privacy controls used before cloud AI calls.')
        ],
        'ai_dictation_phi_scrub' => [
            xl('Enable PHI Scrubber For Cloud Providers'),
            'bool',
            '1',
            xl('Scrub identifiers before sending text to cloud AI providers.')
        ],
        'ai_dictation_scrub_patient_name' => [
            xl('Scrub Patient Name'),
            'bool',
            '1',
            xl('Remove patient names before cloud calls.')
        ],
        'ai_dictation_scrub_dob' => [
            xl('Scrub Date of Birth'),
            'bool',
            '1',
            xl('Remove dates of birth before cloud calls.')
        ],
        'ai_dictation_scrub_mrn' => [
            xl('Scrub MRN'),
            'bool',
            '1',
            xl('Remove medical record numbers before cloud calls.')
        ],
        'ai_dictation_scrub_contact' => [
            xl('Scrub Address / Contact'),
            'bool',
            '1',
            xl('Remove address, phone, and email identifiers before cloud calls.')
        ],
        'ai_dictation_section_llm' => [
            xl('LLM Provider Configuration'),
            'section_header',
            '',
            xl('LLM provider configuration.')
        ],
        'ai_dictation_provider' => [
            xl('Active Provider'),
            [
                'local' => xl('Local Ollama'),
                'gpt' => xl('GPT API - OpenAI'),
                'claude' => xl('Claude API - Anthropic'),
                'gemma' => xl('Gemma / Gemini - Vertex AI'),
            ],
            'gpt',
            xl('Default LLM provider for AI dictation formatting.')
        ],
        'ai_dictation_ollama_endpoint' => [
            xl('Ollama Endpoint'),
            'text',
            'http://localhost:11434',
            xl('Local Ollama server endpoint.')
        ],
        'ai_dictation_ollama_model' => [
            xl('Ollama Model'),
            [
                'clinicalscribe' => 'clinicalscribe',
                'qwen2.5:7b-instruct-q4_K_M' => 'qwen2.5:7b-instruct-q4_K_M',
                'mistral:7b-instruct-q4_K_M' => 'mistral:7b-instruct-q4_K_M',
                'llama3:8b-instruct' => 'llama3:8b-instruct',
                'custom' => xl('Custom'),
            ],
            'clinicalscribe',
            xl('Local Ollama model used for dictation formatting.')
        ],
        'ai_dictation_ollama_custom_model' => [
            xl('Custom Ollama Model Name'),
            'text',
            'clinicalscribe',
            xl('Optional custom Ollama model name.')
        ],
        'ai_dictation_openai_api_key' => [
            xl('OpenAI API Key'),
            'pass',
            '',
            xl('API key used when GPT API is selected.')
        ],
        'ai_dictation_openai_model' => [
            xl('OpenAI GPT Model'),
            [
                'gpt-4o-mini' => 'gpt-4o-mini',
                'gpt-4o' => 'gpt-4o',
                'gpt-4.1-mini' => 'gpt-4.1-mini',
                'gpt-4.1' => 'gpt-4.1',
                'custom' => xl('Custom'),
            ],
            'gpt-4o-mini',
            xl('OpenAI model used when GPT API is selected.')
        ],
        'ai_dictation_openai_custom_model' => [
            xl('Custom OpenAI Model'),
            'text',
            '',
            xl('Optional custom OpenAI model name.')
        ],
        'ai_dictation_claude_api_key' => [
            xl('Claude API Key'),
            'pass',
            '',
            xl('Anthropic API key used when Claude API is selected.')
        ],
        'ai_dictation_claude_model' => [
            xl('Claude Model'),
            [
                'claude-haiku' => xl('Claude Haiku'),
                'claude-sonnet' => xl('Claude Sonnet'),
                'custom' => xl('Custom'),
            ],
            'claude-haiku',
            xl('Claude model used when Claude API is selected.')
        ],
        'ai_dictation_claude_custom_model' => [
            xl('Custom Claude Model'),
            'text',
            '',
            xl('Optional custom Anthropic model name.')
        ],
        'ai_dictation_gemma_api_key' => [
            xl('Google / Vertex API Key'),
            'pass',
            '',
            xl('Google AI Studio or Vertex API key.')
        ],
        'ai_dictation_gemma_project_id' => [
            xl('Vertex AI Project ID'),
            'text',
            '',
            xl('Google Cloud project ID.')
        ],
        'ai_dictation_gemma_region' => [
            xl('Vertex AI Region'),
            [
                'us-central1' => 'us-central1',
                'us-east1' => 'us-east1',
                'us-west1' => 'us-west1',
                'europe-west1' => 'europe-west1',
            ],
            'us-central1',
            xl('Google Cloud region.')
        ],
        'ai_dictation_gemma_model' => [
            xl('Google AI Model'),
            [
                'gemini-2.5-flash' => 'gemini-2.5-flash',
                'gemini-2.0-flash' => 'gemini-2.0-flash',
                'gemma-3-12b-it' => 'gemma-3-12b-it',
                'custom' => xl('Custom'),
            ],
            'gemini-2.5-flash',
            xl('Google AI model used when Gemma / Gemini is selected.')
        ],
        'ai_dictation_section_stt' => [
            xl('Speech-to-Text (Transcription)'),
            'section_header',
            '',
            xl('Speech-to-text configuration.')
        ],
        'ai_dictation_stt_engine' => [
            xl('STT Engine'),
            [
                'chrome' => xl('Chrome Web Speech API'),
                'whisper_cpp' => xl('Whisper.cpp - Local server'),
                'google_cloud' => xl('Google Cloud Speech-to-Text'),
                'deepgram_nova3_medical' => xl('Deepgram Nova-3 Medical'),
                'faster_whisper_auto' => xl('faster-whisper - Auto hardware choice'),
                'faster_whisper_cpu' => xl('faster-whisper - CPU large-v3-turbo'),
                'faster_whisper_gpu' => xl('faster-whisper - NVIDIA GPU large-v3-turbo'),
                'faster_whisper_max' => xl('faster-whisper - Maximum accuracy large-v3'),
            ],
            'chrome',
            xl('Speech-to-text engine used by voice dictation.')
        ],
        'ai_dictation_whisper_endpoint' => [
            xl('Whisper Endpoint'),
            'text',
            'http://localhost:9000',
            xl('Local Whisper.cpp endpoint.')
        ],
        'ai_dictation_faster_whisper_endpoint' => [
            xl('faster-whisper Endpoint'),
            'text',
            'http://127.0.0.1:9010',
            xl('Local faster-whisper server endpoint.')
        ],
        'ai_dictation_faster_whisper_model' => [
            xl('faster-whisper Model'),
            [
                'auto' => xl('Auto'),
                'large-v3-turbo' => 'large-v3-turbo',
                'large-v3' => 'large-v3',
                'medium' => 'medium',
                'small' => 'small',
            ],
            'large-v3-turbo',
            xl('Model used by faster-whisper local transcription.')
        ],
        'ai_dictation_faster_whisper_device' => [
            xl('faster-whisper Device'),
            [
                'auto' => xl('Auto'),
                'cpu' => 'CPU',
                'cuda' => 'NVIDIA CUDA GPU',
            ],
            'auto',
            xl('Hardware device used by faster-whisper.')
        ],
        'ai_dictation_faster_whisper_compute_type' => [
            xl('faster-whisper Compute Type'),
            [
                'auto' => xl('Auto'),
                'int8' => 'int8 (CPU efficient)',
                'float16' => 'float16 (GPU recommended)',
                'int8_float16' => 'int8_float16 (mixed GPU)',
            ],
            'auto',
            xl('Quantization/precision for faster-whisper.')
        ],
        'ai_dictation_google_stt_api_key' => [
            xl('Google Speech API Key'),
            'pass',
            '',
            xl('Dedicated Google Cloud Speech-to-Text API key.')
        ],
        'ai_dictation_deepgram_api_key' => [
            xl('Deepgram API Key'),
            'pass',
            '',
            xl('Deepgram API key for Nova-3 Medical dictation.')
        ],
        'ai_dictation_deepgram_model' => [
            xl('Deepgram Model'),
            [
                'nova-3-medical' => 'nova-3-medical',
                'nova-3' => 'nova-3',
                'nova-2-medical' => 'nova-2-medical',
                'custom' => xl('Custom'),
            ],
            'nova-3-medical',
            xl('Deepgram speech-to-text model.')
        ],
        'ai_dictation_deepgram_custom_model' => [
            xl('Custom Deepgram Model'),
            'text',
            '',
            xl('Custom Deepgram model name when Deepgram Model is Custom.')
        ],
        'ai_dictation_deepgram_keyterms' => [
            xl('Deepgram Medical Keyterms'),
            'text',
            'pelvic inflammatory disease, tubo-ovarian abscess, cervical motion tenderness, adnexal tenderness, CA-125, endometrial biopsy, vulvar lesion, labia majora, suprapubic tenderness, postmenopausal bleeding',
            xl('Comma-separated medical terms sent to Deepgram keyterm prompting. Maximum 100 terms.')
        ],
        'ai_dictation_deepgram_smart_format' => [
            xl('Deepgram Smart Format'),
            'bool',
            '1',
            xl('Enable Deepgram smart formatting.')
        ],
        'ai_dictation_deepgram_dictation' => [
            xl('Deepgram Dictation Mode'),
            'bool',
            '1',
            xl('Enable Deepgram dictation mode for spoken punctuation and formatting cues.')
        ],
        'ai_dictation_deepgram_measurements' => [
            xl('Deepgram Measurements'),
            'bool',
            '1',
            xl('Enable Deepgram measurement formatting for values and units.')
        ],
        'ai_dictation_language' => [
            xl('Language / Accent'),
            [
                'en-BS' => xl('en-BS - English Bahamas'),
                'en-US' => xl('en-US - English United States'),
                'en-GB' => xl('en-GB - English United Kingdom'),
            ],
            'en-BS',
            xl('Language/accent hint for speech recognition.')
        ],
    ];
PHPBLOCK;

function ai_visit_find_square_assignment_end($text, $start)
{
    $equals = strpos($text, '=', $start);
    if ($equals === false) {
        return false;
    }

    $open = strpos($text, '[', $equals);
    if ($open === false) {
        return false;
    }

    $len = strlen($text);
    $depth = 0;
    $inSingle = false;
    $inDouble = false;
    $escape = false;

    for ($i = $open; $i < $len; $i++) {
        $ch = $text[$i];
        if ($escape) {
            $escape = false;
            continue;
        }
        if (($inSingle || $inDouble) && $ch === '\\') {
            $escape = true;
            continue;
        }
        if (!$inDouble && $ch === "'") {
            $inSingle = !$inSingle;
            continue;
        }
        if (!$inSingle && $ch === '"') {
            $inDouble = !$inDouble;
            continue;
        }
        if ($inSingle || $inDouble) {
            continue;
        }
        if ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                $j = $i + 1;
                while ($j < $len && ctype_space($text[$j])) {
                    $j++;
                }
                if ($j < $len && $text[$j] === ';') {
                    return $j + 1;
                }
                return $j;
            }
        }
    }

    return false;
}

$needle = '$GLOBALS_METADATA[\'Voice Dictation\'] = [';
$start = strpos($text, $needle);
if ($start !== false) {
    $lineStart = strrpos(substr($text, 0, $start), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $end = ai_visit_find_square_assignment_end($text, $start);
    if ($end === false) {
        fwrite(STDERR, "Could not parse existing Voice Dictation config." . PHP_EOL);
        exit(1);
    }
    $text = substr($text, 0, $lineStart) . $block . substr($text, $end);
} else {
    $insertBefore = strpos($text, '$globalsInitEvent = new GlobalsInitializedEvent');
    if ($insertBefore === false) {
        fwrite(STDERR, "Could not find OpenEMR 8 globals init event." . PHP_EOL);
        exit(1);
    }
    $lineStart = strrpos(substr($text, 0, $insertBefore), "\n");
    $lineStart = $lineStart === false ? $insertBefore : $lineStart + 1;
    $text = substr($text, 0, $lineStart) . $block . "\n\n" . substr($text, $lineStart);
}

file_put_contents($file, $text);
echo "Ensured OpenEMR 8 Voice Dictation Config menu. Backup: " . $backup . PHP_EOL;
