<?php
/**
 * Apply hardware-aware local STT defaults to an OpenEMR database.
 */

$root = $argv[1] ?? '/var/www/html/clinic';
$sqlconf = rtrim($root, '/') . '/sites/default/sqlconf.php';
if (!is_file($sqlconf)) {
    fwrite(STDERR, "OpenEMR sqlconf.php not found: " . $sqlconf . PHP_EOL);
    exit(1);
}

include $sqlconf;

$mysqli = @new mysqli($host, $login, $pass, $dbase, (int)$port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Database connection failed: " . $mysqli->connect_error . PHP_EOL);
    exit(1);
}

$defaults = [
    'ai_dictation_stt_engine' => 'faster_whisper_auto',
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
    'ai_dictation_ollama_model' => 'custom',
    'ai_dictation_ollama_custom_model' => 'clinicalscribe',
    'ai_dictation_gemma_model' => 'gemini-2.5-flash',
];

$secretNames = array(
    'ai_dictation_deepgram_api_key',
);

foreach ($defaults as $name => $value) {
    $stmt = $mysqli->prepare('INSERT INTO globals (gl_name, gl_index, gl_value) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE gl_value = VALUES(gl_value)');
    $stmt->bind_param('ss', $name, $value);
    $stmt->execute();
    $stmt->close();
}

foreach ($secretNames as $name) {
    $blank = '';
    $stmt = $mysqli->prepare('INSERT INTO globals (gl_name, gl_index, gl_value) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE gl_value = gl_value');
    $stmt->bind_param('ss', $name, $blank);
    $stmt->execute();
    $stmt->close();
}

echo "Local STT globals applied.\n";
