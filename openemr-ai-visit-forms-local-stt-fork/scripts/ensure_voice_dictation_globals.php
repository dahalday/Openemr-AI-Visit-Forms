<?php
/**
 * Ensure OpenEMR has the complete Voice Dictation globals tab.
 */

$root = $argv[1] ?? '/var/www/html/clinic';
$file = rtrim($root, '/') . '/library/globals.inc.php';
if (!is_file($file)) {
    fwrite(STDERR, "globals.inc.php not found: " . $file . PHP_EOL);
    exit(1);
}

$text = file_get_contents($file);
$backup = $file . '.bak-voice-dictation-full-' . date('Ymd-His');
copy($file, $backup);

$block = <<<'PHPBLOCK'
    // Voice Dictation Tab
    //
    'Voice Dictation' => array(
        'ai_dictation_section_general' => array(
            xl('General - Voice Dictation Module'),
            'section_header',
            '',
            xl('Voice Dictation Module general settings.')
        ),

        'ai_dictation_enabled' => array(
            xl('Enable Voice Dictation'),
            'bool',
            '1',
            xl('Activate the AI dictation tab within encounter forms.')
        ),

        'ai_dictation_default_mode' => array(
            xl('Default Dictation Mode'),
            array(
                'hpc' => xl('HPC - History of Presenting Complaint'),
                'soap' => xl('SOAP - Subjective / Objective / Assessment / Plan'),
                'plain' => xl('Plain Dictation'),
            ),
            'hpc',
            xl('Default note mode when opening the dictation form.')
        ),

        'ai_dictation_specialty_template' => array(
            xl('Specialty Template'),
            array(
                'obgyn' => xl('OB/GYN'),
                'general' => xl('General Medicine'),
                'emergency' => xl('Emergency Medicine'),
                'custom' => xl('Custom'),
            ),
            'obgyn',
            xl('Default clinical vocabulary/template for formatting.')
        ),

        'ai_dictation_auto_populate' => array(
            xl('Auto-populate Fields After Formatting'),
            'bool',
            '1',
            xl('Automatically place AI formatted output into note fields after review.')
        ),

        'ai_dictation_require_review' => array(
            xl('Require Review Before Save'),
            'bool',
            '1',
            xl('Require clinician review before formatted content is saved.')
        ),

        'ai_dictation_database' => array(
            xl('Dictation Database'),
            'text',
            'periomed_dictation',
            xl('Optional external dictation database name.')
        ),

        'ai_dictation_section_phi' => array(
            xl('PHI Scrubbing'),
            'section_header',
            '',
            xl('Privacy controls used before cloud AI calls.')
        ),

        'ai_dictation_phi_scrub' => array(
            xl('Enable PHI Scrubber For Cloud Providers'),
            'bool',
            '1',
            xl('Scrub identifiers before sending text to cloud AI providers.')
        ),

        'ai_dictation_scrub_patient_name' => array(
            xl('Scrub Patient Name'),
            'bool',
            '1',
            xl('Remove patient names before cloud calls.')
        ),

        'ai_dictation_scrub_dob' => array(
            xl('Scrub Date of Birth'),
            'bool',
            '1',
            xl('Remove dates of birth before cloud calls.')
        ),

        'ai_dictation_scrub_mrn' => array(
            xl('Scrub MRN'),
            'bool',
            '1',
            xl('Remove medical record numbers before cloud calls.')
        ),

        'ai_dictation_scrub_contact' => array(
            xl('Scrub Address / Contact'),
            'bool',
            '1',
            xl('Remove address, phone, and email identifiers before cloud calls.')
        ),

        'ai_dictation_section_llm' => array(
            xl('LLM Provider Configuration'),
            'section_header',
            '',
            xl('LLM provider configuration.')
        ),

        'ai_dictation_provider' => array(
            xl('Active Provider'),
            array(
                'local' => xl('Local - Ollama'),
                'gpt' => xl('GPT API - OpenAI'),
                'claude' => xl('Claude API - Anthropic'),
                'gemma' => xl('Gemma / Gemini - Vertex AI'),
            ),
            'gpt',
            xl('Default LLM provider for AI dictation formatting.')
        ),

        'ai_dictation_ollama_endpoint' => array(
            xl('Ollama Endpoint'),
            'text',
            'http://localhost:11434',
            xl('Local Ollama server endpoint.')
        ),

        'ai_dictation_ollama_model' => array(
            xl('Ollama Model'),
            array(
                'mistral:7b-instruct-q4_K_M' => 'mistral:7b-instruct-q4_K_M',
                'llama3:8b-instruct' => 'llama3:8b-instruct',
                'clinicalscribe' => 'clinicalscribe',
                'custom' => xl('Custom'),
            ),
            'clinicalscribe',
            xl('Local Ollama model used for dictation formatting.')
        ),

        'ai_dictation_ollama_custom_model' => array(
            xl('Custom Ollama Model Name'),
            'text',
            'clinicalscribe',
            xl('Optional custom Ollama model name.')
        ),

        'ai_dictation_openai_api_key' => array(
            xl('OpenAI API Key'),
            'pass',
            '',
            xl('API key used when GPT API is selected.')
        ),

        'ai_dictation_openai_model' => array(
            xl('OpenAI GPT Model'),
            array(
                'gpt-4o-mini' => 'gpt-4o-mini',
                'gpt-4o' => 'gpt-4o',
                'gpt-4.1-mini' => 'gpt-4.1-mini',
                'gpt-4.1' => 'gpt-4.1',
                'custom' => xl('Custom'),
            ),
            'gpt-4o-mini',
            xl('OpenAI model used when GPT API is selected.')
        ),

        'ai_dictation_openai_custom_model' => array(
            xl('Custom OpenAI Model'),
            'text',
            '',
            xl('Optional custom OpenAI model name.')
        ),

        'ai_dictation_claude_api_key' => array(
            xl('Claude API Key'),
            'pass',
            '',
            xl('Anthropic API key used when Claude API is selected.')
        ),

        'ai_dictation_claude_model' => array(
            xl('Claude Model'),
            array(
                'claude-haiku' => xl('Claude Haiku'),
                'claude-sonnet' => xl('Claude Sonnet'),
                'custom' => xl('Custom'),
            ),
            'claude-haiku',
            xl('Claude model used when Claude API is selected.')
        ),

        'ai_dictation_claude_custom_model' => array(
            xl('Custom Claude Model'),
            'text',
            '',
            xl('Optional custom Anthropic model name.')
        ),

        'ai_dictation_gemma_api_key' => array(
            xl('Google / Vertex API Key'),
            'pass',
            '',
            xl('Google AI or Vertex API key.')
        ),

        'ai_dictation_gemma_project_id' => array(
            xl('Vertex AI Project ID'),
            'text',
            '',
            xl('Google Cloud project ID.')
        ),

        'ai_dictation_gemma_region' => array(
            xl('Vertex AI Region'),
            array(
                'us-central1' => 'us-central1',
                'us-east1' => 'us-east1',
                'us-west1' => 'us-west1',
                'europe-west1' => 'europe-west1',
            ),
            'us-central1',
            xl('Google Cloud region.')
        ),

        'ai_dictation_gemma_model' => array(
            xl('Google AI Model'),
            array(
                'gemini-2.5-flash' => 'gemini-2.5-flash',
                'gemini-2.0-flash' => 'gemini-2.0-flash',
                'gemma-3-12b-it' => 'gemma-3-12b-it',
                'custom' => xl('Custom'),
            ),
            'gemini-2.5-flash',
            xl('Google AI model used when Gemma / Gemini is selected.')
        ),

        'ai_dictation_section_stt' => array(
            xl('Speech-to-Text (Transcription)'),
            'section_header',
            '',
            xl('Speech-to-text configuration.')
        ),

        'ai_dictation_stt_engine' => array(
            xl('STT Engine'),
            array(
                'chrome' => xl('Chrome Web Speech API'),
                'whisper_cpp' => xl('Whisper.cpp - Local server'),
                'google_cloud' => xl('Google Cloud Speech-to-Text'),
                'deepgram_nova3_medical' => xl('Deepgram Nova-3 Medical'),
                'faster_whisper_auto' => xl('faster-whisper - Auto hardware choice'),
                'faster_whisper_cpu' => xl('faster-whisper - CPU large-v3-turbo'),
                'faster_whisper_gpu' => xl('faster-whisper - NVIDIA GPU large-v3-turbo'),
                'faster_whisper_max' => xl('faster-whisper - Maximum accuracy large-v3'),
            ),
            'chrome',
            xl('Speech-to-text engine used by voice dictation.')
        ),

        'ai_dictation_whisper_endpoint' => array(
            xl('Whisper Endpoint'),
            'text',
            'http://localhost:9000',
            xl('Local Whisper.cpp endpoint.')
        ),

        'ai_dictation_faster_whisper_endpoint' => array(
            xl('faster-whisper Endpoint'),
            'text',
            'http://127.0.0.1:9010',
            xl('Local faster-whisper server endpoint.')
        ),

        'ai_dictation_faster_whisper_model' => array(
            xl('faster-whisper Model'),
            array(
                'auto' => xl('Auto'),
                'large-v3-turbo' => 'large-v3-turbo',
                'large-v3' => 'large-v3',
                'medium' => 'medium',
                'small' => 'small',
            ),
            'large-v3-turbo',
            xl('Model used by faster-whisper local transcription.')
        ),

        'ai_dictation_faster_whisper_device' => array(
            xl('faster-whisper Device'),
            array(
                'auto' => xl('Auto'),
                'cpu' => 'CPU',
                'cuda' => 'NVIDIA CUDA GPU',
            ),
            'auto',
            xl('Hardware device used by faster-whisper.')
        ),

        'ai_dictation_faster_whisper_compute_type' => array(
            xl('faster-whisper Compute Type'),
            array(
                'auto' => xl('Auto'),
                'int8' => 'int8 (CPU efficient)',
                'float16' => 'float16 (GPU recommended)',
                'int8_float16' => 'int8_float16 (mixed GPU)',
            ),
            'auto',
            xl('Quantization/precision for faster-whisper.')
        ),

        'ai_dictation_google_stt_api_key' => array(
            xl('Google Speech API Key'),
            'pass',
            '',
            xl('Dedicated Google Cloud Speech-to-Text API key.')
        ),

        'ai_dictation_deepgram_api_key' => array(
            xl('Deepgram API Key'),
            'pass',
            '',
            xl('Deepgram API key for Nova-3 Medical dictation.')
        ),

        'ai_dictation_deepgram_model' => array(
            xl('Deepgram Model'),
            array(
                'nova-3-medical' => 'nova-3-medical',
                'nova-3' => 'nova-3',
                'nova-2-medical' => 'nova-2-medical',
                'custom' => xl('Custom'),
            ),
            'nova-3-medical',
            xl('Deepgram speech-to-text model.')
        ),

        'ai_dictation_deepgram_custom_model' => array(
            xl('Custom Deepgram Model'),
            'text',
            '',
            xl('Custom Deepgram model name when Deepgram Model is Custom.')
        ),

        'ai_dictation_deepgram_keyterms' => array(
            xl('Deepgram Medical Keyterms'),
            'text',
            'pelvic inflammatory disease, tubo-ovarian abscess, cervical motion tenderness, adnexal tenderness, CA-125, endometrial biopsy, vulvar lesion, labia majora, suprapubic tenderness, postmenopausal bleeding',
            xl('Comma-separated medical terms sent to Deepgram keyterm prompting. Maximum 100 terms.')
        ),

        'ai_dictation_deepgram_smart_format' => array(
            xl('Deepgram Smart Format'),
            'bool',
            '1',
            xl('Enable Deepgram smart formatting.')
        ),

        'ai_dictation_deepgram_dictation' => array(
            xl('Deepgram Dictation Mode'),
            'bool',
            '1',
            xl('Enable Deepgram dictation mode for spoken punctuation and formatting cues.')
        ),

        'ai_dictation_deepgram_measurements' => array(
            xl('Deepgram Measurements'),
            'bool',
            '1',
            xl('Enable Deepgram measurement formatting for values and units.')
        ),

        'ai_dictation_language' => array(
            xl('Language / Accent'),
            array(
                'en-BS' => xl('en-BS - English Bahamas'),
                'en-US' => xl('en-US - English United States'),
                'en-GB' => xl('en-GB - English United Kingdom'),
            ),
            'en-BS',
            xl('Language/accent hint for speech recognition.')
        ),
    ),
PHPBLOCK;

function ai_visit_find_voice_tab_end($text, $start)
{
    $len = strlen($text);
    $depth = 0;
    $inSingle = false;
    $inDouble = false;
    $escape = false;
    $seenArrayOpen = false;

    for ($i = $start; $i < $len; $i++) {
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
        if ($ch === '(') {
            $depth++;
            $seenArrayOpen = true;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            if ($seenArrayOpen && $depth === 0) {
                $j = $i + 1;
                while ($j < $len && ctype_space($text[$j])) {
                    $j++;
                }
                if ($j < $len && $text[$j] === ',') {
                    $j++;
                }
                return $j;
            }
        }
    }

    return false;
}

$tab = strpos($text, "'Voice Dictation' => array(");
if ($tab !== false) {
    $lineStart = strrpos(substr($text, 0, $tab), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $commentStart = strrpos(substr($text, 0, $lineStart), "\n    // Voice Dictation Tab");
    if ($commentStart !== false) {
        $lineStart = $commentStart + 1;
    }
    $end = ai_visit_find_voice_tab_end($text, $tab);
    if ($end === false) {
        fwrite(STDERR, "Could not parse existing Voice Dictation tab." . PHP_EOL);
        exit(1);
    }
    $text = substr($text, 0, $lineStart) . $block . substr($text, $end);
} else {
    $pos = strrpos($text, "\n);");
    if ($pos === false) {
        fwrite(STDERR, "Could not find end of globals array." . PHP_EOL);
        exit(1);
    }
    $text = substr($text, 0, $pos) . "\n" . $block . substr($text, $pos);
}

file_put_contents($file, $text);
echo "Ensured full Voice Dictation globals. Backup: " . $backup . PHP_EOL;
