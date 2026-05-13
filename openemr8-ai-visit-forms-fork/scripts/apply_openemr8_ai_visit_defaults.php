<?php
/**
 * Apply safe AI Visit Forms defaults for OpenEMR 8 without overwriting API keys.
 */

$root = $argv[1] ?? '/var/www/html/openemr8';
$sqlconf = rtrim($root, '/') . '/sites/default/sqlconf.php';
if (!is_file($sqlconf)) {
    fwrite(STDERR, "sqlconf.php not found: " . $sqlconf . PHP_EOL);
    exit(1);
}

include $sqlconf;
$mysqli = new mysqli($host, $login, $pass, $dbase, (int)$port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Database connection failed: " . $mysqli->connect_error . PHP_EOL);
    exit(1);
}

$defaults = array(
    'ai_dictation_enabled' => '1',
    'ai_dictation_default_mode' => 'hpc',
    'ai_dictation_specialty_template' => 'obgyn',
    'ai_dictation_auto_populate' => '1',
    'ai_dictation_require_review' => '1',
    'ai_dictation_phi_scrub' => '1',
    'ai_dictation_scrub_patient_name' => '1',
    'ai_dictation_scrub_dob' => '1',
    'ai_dictation_scrub_mrn' => '1',
    'ai_dictation_scrub_contact' => '1',
    'ai_dictation_provider' => 'gpt',
    'ai_dictation_openai_model' => 'gpt-4o-mini',
    'ai_dictation_claude_model' => 'claude-haiku',
    'ai_dictation_gemma_model' => 'gemini-2.5-flash',
    'ai_dictation_gemma_region' => 'us-central1',
    'ai_dictation_ollama_endpoint' => 'http://localhost:11434',
    'ai_dictation_ollama_model' => 'custom',
    'ai_dictation_ollama_custom_model' => 'clinicalscribe',
    'ai_dictation_stt_engine' => 'chrome',
    'ai_dictation_whisper_endpoint' => 'http://localhost:9000',
    'ai_dictation_faster_whisper_endpoint' => 'http://127.0.0.1:9010',
    'ai_dictation_faster_whisper_model' => 'large-v3-turbo',
    'ai_dictation_faster_whisper_device' => 'auto',
    'ai_dictation_faster_whisper_compute_type' => 'auto',
    'ai_dictation_deepgram_model' => 'nova-3-medical',
    'ai_dictation_deepgram_custom_model' => '',
    'ai_dictation_deepgram_keyterms' => 'pelvic inflammatory disease, tubo-ovarian abscess, cervical motion tenderness, adnexal tenderness, CA-125, endometrial biopsy, vulvar lesion, labia majora, suprapubic tenderness, postmenopausal bleeding',
    'ai_dictation_deepgram_smart_format' => '1',
    'ai_dictation_deepgram_dictation' => '1',
    'ai_dictation_deepgram_measurements' => '1',
    'ai_dictation_language' => 'en-BS',
);

foreach ($defaults as $name => $value) {
    $stmt = $mysqli->prepare(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE gl_value = IF(gl_value = '', VALUES(gl_value), gl_value)"
    );
    $stmt->bind_param('ss', $name, $value);
    if (!$stmt->execute()) {
        fwrite(STDERR, "Could not save " . $name . ": " . $stmt->error . PHP_EOL);
        exit(1);
    }
    $stmt->close();
}

$mysqli->query("UPDATE globals SET gl_value = 'gpt' WHERE gl_name = 'ai_dictation_provider' AND gl_index = 0 AND gl_value = 'openai'");

$secretNames = array(
    'ai_dictation_openai_api_key',
    'ai_dictation_claude_api_key',
    'ai_dictation_gemma_api_key',
    'ai_dictation_google_stt_api_key',
    'ai_dictation_deepgram_api_key',
    'ai_dictation_openai_custom_model',
    'ai_dictation_claude_custom_model',
    'ai_dictation_gemma_project_id',
);

foreach ($secretNames as $name) {
    $blank = '';
    $stmt = $mysqli->prepare(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE gl_value = gl_value"
    );
    $stmt->bind_param('ss', $name, $blank);
    if (!$stmt->execute()) {
        fwrite(STDERR, "Could not ensure " . $name . ": " . $stmt->error . PHP_EOL);
        exit(1);
    }
    $stmt->close();
}

echo "OpenEMR 8 AI Visit Forms defaults applied." . PHP_EOL;
