<?php
/**
 * Same-origin Whisper.cpp proxy for long AI Visit Forms dictation.
 *
 * @package OpenEMR
 */

require_once(__DIR__ . "/../../globals.php");

header('Content-Type: application/json');
@set_time_limit(180);

function ai_visit_transcribe_response($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function ai_visit_transcribe_global($name, $default = '')
{
    return isset($GLOBALS[$name]) && $GLOBALS[$name] !== '' ? $GLOBALS[$name] : $default;
}

function ai_visit_transcribe_google_key()
{
    $key = ai_visit_transcribe_global('ai_dictation_google_stt_api_key', '');
    if ($key === '') {
        $key = getenv('GOOGLE_SPEECH_API_KEY') ?: getenv('GOOGLE_CLOUD_SPEECH_API_KEY') ?: '';
    }
    return trim((string)$key);
}

function ai_visit_transcribe_google_cloud($wavPath)
{
    $key = ai_visit_transcribe_google_key();
    if ($key === '') {
        ai_visit_transcribe_response(array(
            'error' => 'Google Cloud Speech-to-Text is selected but no Google Speech API key is configured. Set Global Settings > Voice Dictation > Google Speech API Key.',
            'fatal' => true
        ), 400);
    }

    $language = ai_visit_transcribe_global('ai_dictation_language', 'en-US');
    if ($language === '') {
        $language = 'en-US';
    }

    $payload = array(
        'config' => array(
            'encoding' => 'LINEAR16',
            'sampleRateHertz' => 16000,
            'languageCode' => $language,
            'enableAutomaticPunctuation' => true,
        ),
        'audio' => array(
            'content' => base64_encode(file_get_contents($wavPath)),
        ),
    );

    $ch = curl_init('https://speech.googleapis.com/v1/speech:recognize?key=' . rawurlencode($key));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        ai_visit_transcribe_response(array('error' => 'Google Speech request failed: ' . $error), 502);
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && isset($decoded['error']['message'])
            ? $decoded['error']['message']
            : substr((string)$response, 0, 500);
        $fatal = in_array((int)$status, array(401, 403), true);
        $hint = '';
        if ($fatal) {
            $hint = ' Enable Cloud Speech-to-Text API in the Google Cloud project tied to this API key and make sure billing and API key restrictions allow speech.googleapis.com.';
            if (stripos($message, 'blocked') !== false) {
                $hint = ' The API key restrictions are blocking speech.googleapis.com. In Google Cloud Console > APIs and services > Credentials, open this key and add Cloud Speech-to-Text API under API restrictions, or set restrictions to None while testing.';
            }
        }
        ai_visit_transcribe_response(array(
            'error' => 'Google Speech returned HTTP ' . $status,
            'body' => trim($message . $hint),
            'fatal' => $fatal
        ), $fatal ? 400 : 502);
    }

    $parts = array();
    if (is_array($decoded) && !empty($decoded['results'])) {
        foreach ($decoded['results'] as $result) {
            if (!empty($result['alternatives'][0]['transcript'])) {
                $parts[] = trim((string)$result['alternatives'][0]['transcript']);
            }
        }
    }

    return trim(implode(' ', array_filter($parts)));
}

function ai_visit_transcribe_deepgram_key()
{
    $key = ai_visit_transcribe_global('ai_dictation_deepgram_api_key', '');
    if ($key === '') {
        $key = getenv('DEEPGRAM_API_KEY') ?: '';
    }
    return trim((string)$key);
}

function ai_visit_transcribe_bool_global($name, $default)
{
    $value = ai_visit_transcribe_global($name, $default ? '1' : '0');
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function ai_visit_transcribe_deepgram_model()
{
    $model = trim((string)ai_visit_transcribe_global('ai_dictation_deepgram_model', 'nova-3-medical'));
    if ($model === '' || $model === 'custom') {
        $model = trim((string)ai_visit_transcribe_global('ai_dictation_deepgram_custom_model', 'nova-3-medical'));
    }
    return $model !== '' ? $model : 'nova-3-medical';
}

function ai_visit_transcribe_deepgram_language()
{
    $language = trim((string)ai_visit_transcribe_global('ai_dictation_language', 'en-US'));
    if ($language === '' || $language === 'en-BS') {
        return 'en-US';
    }
    if (in_array($language, array('en', 'en-US', 'en-GB'), true)) {
        return $language;
    }
    return 'en-US';
}

function ai_visit_transcribe_deepgram_default_keyterms()
{
    return 'pelvic inflammatory disease, tubo-ovarian abscess, cervical motion tenderness, adnexal tenderness, CA-125, endometrial biopsy, vulvar lesion, labia majora, suprapubic tenderness, postmenopausal bleeding, total abdominal hysterectomy, bilateral salpingo-oophorectomy';
}

function ai_visit_transcribe_deepgram_keyterms()
{
    $value = trim((string)ai_visit_transcribe_global('ai_dictation_deepgram_keyterms', ai_visit_transcribe_deepgram_default_keyterms()));
    if ($value === '') {
        return array();
    }

    $terms = preg_split('/[\r\n,]+/', $value);
    $clean = array();
    foreach ($terms as $term) {
        $term = trim((string)$term);
        if ($term !== '' && !in_array($term, $clean, true)) {
            $clean[] = $term;
        }
        if (count($clean) >= 100) {
            break;
        }
    }
    return $clean;
}

function ai_visit_transcribe_deepgram($wavPath)
{
    $key = ai_visit_transcribe_deepgram_key();
    if ($key === '') {
        ai_visit_transcribe_response(array(
            'error' => 'Deepgram Nova-3 Medical is selected but no Deepgram API key is configured. Set Global Settings > Voice Dictation > Deepgram API Key.',
            'fatal' => true
        ), 400);
    }

    $params = array(
        'model' => ai_visit_transcribe_deepgram_model(),
        'language' => ai_visit_transcribe_deepgram_language(),
        'smart_format' => ai_visit_transcribe_bool_global('ai_dictation_deepgram_smart_format', true) ? 'true' : 'false',
        'dictation' => ai_visit_transcribe_bool_global('ai_dictation_deepgram_dictation', true) ? 'true' : 'false',
        'measurements' => ai_visit_transcribe_bool_global('ai_dictation_deepgram_measurements', true) ? 'true' : 'false',
        'punctuate' => 'true',
    );

    $query = array();
    foreach ($params as $name => $value) {
        $query[] = rawurlencode($name) . '=' . rawurlencode($value);
    }
    foreach (ai_visit_transcribe_deepgram_keyterms() as $term) {
        $query[] = 'keyterm=' . rawurlencode($term);
    }

    $audio = file_get_contents($wavPath);
    if ($audio === false || $audio === '') {
        ai_visit_transcribe_response(array('error' => 'Could not read converted audio for Deepgram transcription.'), 500);
    }

    $ch = curl_init('https://api.deepgram.com/v1/listen?' . implode('&', $query));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Token ' . $key,
        'Content-Type: audio/wav',
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $audio);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        ai_visit_transcribe_response(array('error' => 'Deepgram request failed: ' . $error), 502);
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && isset($decoded['err_msg'])
            ? $decoded['err_msg']
            : substr((string)$response, 0, 500);
        $fatal = in_array((int)$status, array(401, 403), true);
        ai_visit_transcribe_response(array(
            'error' => 'Deepgram returned HTTP ' . $status,
            'body' => trim((string)$message),
            'fatal' => $fatal
        ), $fatal ? 400 : 502);
    }

    $parts = array();
    if (is_array($decoded) && !empty($decoded['results']['channels'])) {
        foreach ($decoded['results']['channels'] as $channel) {
            if (!empty($channel['alternatives'][0]['transcript'])) {
                $parts[] = trim((string)$channel['alternatives'][0]['transcript']);
            }
        }
    }

    return trim(implode(' ', array_filter($parts)));
}

function ai_visit_transcribe_faster_endpoint()
{
    $endpoint = ai_visit_transcribe_global('ai_dictation_faster_whisper_endpoint', 'http://127.0.0.1:9010');
    $endpoint = rtrim((string)$endpoint, '/');
    return $endpoint !== '' ? $endpoint : 'http://127.0.0.1:9010';
}

function ai_visit_transcribe_faster_setting($name, $default)
{
    $value = ai_visit_transcribe_global($name, $default);
    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

function ai_visit_transcribe_faster_preset($engine)
{
    if ($engine === 'faster_whisper_cpu') {
        return 'cpu';
    }
    if ($engine === 'faster_whisper_gpu') {
        return 'gpu';
    }
    if ($engine === 'faster_whisper_max') {
        return 'max_accuracy';
    }
    return 'auto';
}

function ai_visit_transcribe_faster_whisper($wavPath, $engine)
{
    $endpoint = ai_visit_transcribe_faster_endpoint();
    $preset = ai_visit_transcribe_faster_preset($engine);
    $model = ai_visit_transcribe_faster_setting('ai_dictation_faster_whisper_model', 'large-v3-turbo');
    $device = ai_visit_transcribe_faster_setting('ai_dictation_faster_whisper_device', 'auto');
    $compute = ai_visit_transcribe_faster_setting('ai_dictation_faster_whisper_compute_type', 'auto');
    $language = ai_visit_transcribe_global('ai_dictation_language', 'en-US');
    if ($language === '') {
        $language = 'en-US';
    }

    $audio = new CURLFile($wavPath, 'audio/wav', 'dictation.wav');
    $postFields = array(
        'file' => $audio,
        'preset' => $preset,
        'model' => $model,
        'device' => $device,
        'compute_type' => $compute,
        'language' => $language,
        'vad_filter' => '1',
    );

    $ch = curl_init($endpoint . '/transcribe');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 220);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        ai_visit_transcribe_response(array(
            'error' => 'faster-whisper request failed: ' . $error,
            'body' => 'Start the local faster-whisper server or choose another STT engine.'
        ), 502);
    }
    if ($status < 200 || $status >= 300) {
        ai_visit_transcribe_response(array(
            'error' => 'faster-whisper returned HTTP ' . $status,
            'body' => substr((string)$response, 0, 500)
        ), 502);
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded) && isset($decoded['text'])) {
        return trim((string)$decoded['text']);
    }
    return trim((string)$response);
}

if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
    ai_visit_transcribe_response(array('error' => 'No audio chunk was uploaded.'), 400);
}

$engine = isset($_POST['engine']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_POST['engine']) : '';
if ($engine === '') {
    $engine = ai_visit_transcribe_global('ai_dictation_stt_engine', 'whisper_cpp');
}
if (!in_array($engine, array('whisper_cpp', 'google_cloud', 'deepgram_nova3_medical', 'faster_whisper_auto', 'faster_whisper_cpu', 'faster_whisper_gpu', 'faster_whisper_max'), true)) {
    $engine = 'whisper_cpp';
}

$endpoint = rtrim(ai_visit_transcribe_global('ai_dictation_whisper_endpoint', 'http://127.0.0.1:9000'), '/');
if ($endpoint === '') {
    $endpoint = 'http://127.0.0.1:9000';
}

$fileName = isset($_FILES['audio']['name']) ? $_FILES['audio']['name'] : 'dictation.webm';
$mimeType = isset($_FILES['audio']['type']) && $_FILES['audio']['type'] !== '' ? $_FILES['audio']['type'] : 'audio/webm';
$inputPath = $_FILES['audio']['tmp_name'];
$uploadSize = isset($_FILES['audio']['size']) ? (int)$_FILES['audio']['size'] : 0;
if ($uploadSize <= 0) {
    ai_visit_transcribe_response(array('error' => 'Uploaded audio chunk was empty.'), 400);
}

$wavPath = tempnam(sys_get_temp_dir(), 'ai_visit_stt_');
if ($wavPath === false) {
    ai_visit_transcribe_response(array('error' => 'Could not create temporary WAV file.'), 500);
}
$wavPath .= '.wav';

$cmd = 'ffmpeg -y -loglevel error -i ' . escapeshellarg($inputPath) .
    ' -ac 1 -ar 16000 -c:a pcm_s16le ' . escapeshellarg($wavPath) . ' 2>&1';
$ffmpegOutput = array();
$ffmpegStatus = 0;
exec($cmd, $ffmpegOutput, $ffmpegStatus);
if ($ffmpegStatus !== 0 || !file_exists($wavPath) || filesize($wavPath) < 1000) {
    @unlink($wavPath);
    ai_visit_transcribe_response(array(
        'error' => 'Could not convert browser audio for speech transcription.',
        'detail' => substr(implode("\n", $ffmpegOutput), 0, 500),
        'mime' => $mimeType,
        'filename' => $fileName,
        'size' => $uploadSize,
    ), 422);
}

if ($engine === 'google_cloud') {
    $text = ai_visit_transcribe_google_cloud($wavPath);
    @unlink($wavPath);
    ai_visit_transcribe_response(array('text' => $text));
}

if ($engine === 'deepgram_nova3_medical') {
    $text = ai_visit_transcribe_deepgram($wavPath);
    @unlink($wavPath);
    ai_visit_transcribe_response(array('text' => $text));
}

if (strpos($engine, 'faster_whisper') === 0) {
    $text = ai_visit_transcribe_faster_whisper($wavPath, $engine);
    @unlink($wavPath);
    ai_visit_transcribe_response(array('text' => $text));
}

$audio = new CURLFile($wavPath, 'audio/wav', 'dictation.wav');

$postFields = array(
    'file' => $audio,
    'temperature' => '0.0',
    'temperature_inc' => '0.2',
    'response_format' => 'json',
);

$ch = curl_init($endpoint . '/inference');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 160);
$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($wavPath);

if ($errno) {
    ai_visit_transcribe_response(array('error' => 'Whisper request failed: ' . $error), 502);
}
if ($status < 200 || $status >= 300) {
    ai_visit_transcribe_response(array('error' => 'Whisper returned HTTP ' . $status, 'body' => substr((string)$response, 0, 500)), 502);
}

$decoded = json_decode($response, true);
if (is_array($decoded) && isset($decoded['text'])) {
    ai_visit_transcribe_response(array('text' => trim((string)$decoded['text'])));
}

ai_visit_transcribe_response(array('text' => trim((string)$response)));
