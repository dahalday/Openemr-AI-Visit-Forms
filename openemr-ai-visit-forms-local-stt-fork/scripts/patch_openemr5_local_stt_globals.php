<?php
/**
 * Patch OpenEMR 5 globals metadata with faster-whisper STT options.
 */

$root = $argv[1] ?? '/var/www/html/clinic';
$file = rtrim($root, '/') . '/library/globals.inc.php';
if (!is_file($file)) {
    fwrite(STDERR, "globals.inc.php not found: " . $file . PHP_EOL);
    exit(1);
}

$text = file_get_contents($file);
$backup = $file . '.bak-local-stt-' . date('Ymd-His');
copy($file, $backup);

$search = <<<'PHP'
        'ai_dictation_stt_engine' => array(
            xl('STT Engine'),
            array(
                'chrome' => xl('Chrome Web Speech API (built-in - no server needed)'),
                'whisper_cpp' => xl('Whisper.cpp - Local server (higher accuracy)'),
                'google_cloud' => xl('Google Cloud Speech-to-Text (cloud)'),
            ),
            'chrome',
            xl('Speech-to-text engine used by voice dictation.')
        ),
PHP;

$replace = <<<'PHP'
        'ai_dictation_stt_engine' => array(
            xl('STT Engine'),
            array(
                'chrome' => xl('Chrome Web Speech API (built-in - no server needed)'),
                'whisper_cpp' => xl('Whisper.cpp - Local server (portable CPU)'),
                'google_cloud' => xl('Google Cloud Speech-to-Text (cloud)'),
                'deepgram_nova3_medical' => xl('Deepgram Nova-3 Medical (cloud medical STT)'),
                'faster_whisper_auto' => xl('faster-whisper - Auto hardware choice'),
                'faster_whisper_cpu' => xl('faster-whisper - CPU large-v3-turbo'),
                'faster_whisper_gpu' => xl('faster-whisper - NVIDIA GPU large-v3-turbo'),
                'faster_whisper_max' => xl('faster-whisper - Maximum accuracy large-v3'),
            ),
            'faster_whisper_auto',
            xl('Speech-to-text engine used by voice dictation.')
        ),
PHP;

if (strpos($text, "'faster_whisper_auto'") === false) {
    $text = str_replace($search, $replace, $text);
}

if (strpos($text, "'ai_dictation_faster_whisper_endpoint'") === false) {
    $needle = <<<'PHP'
        'ai_dictation_whisper_endpoint' => array(
            xl('Whisper Endpoint'),
            'text',
            'http://localhost:9000',
            xl('Local Whisper endpoint if Whisper.cpp is selected.')
        ),
PHP;
    $insert = $needle . <<<'PHP'

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
                'large-v3-turbo' => 'large-v3-turbo (recommended)',
                'large-v3' => 'large-v3 (maximum accuracy, slower)',
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
PHP;
    $text = str_replace($needle, $insert, $text);
}

file_put_contents($file, $text);
echo "Patched globals.inc.php for local STT. Backup: " . $backup . PHP_EOL;
