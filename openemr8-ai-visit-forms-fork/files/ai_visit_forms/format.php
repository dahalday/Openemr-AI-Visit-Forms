<?php
/**
 * AI formatter endpoint for AI Visit Forms.
 *
 * @package OpenEMR
 */

require_once(__DIR__ . "/../../globals.php");

header('Content-Type: application/json');
@set_time_limit(700);

function ai_visit_json_response($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function ai_visit_read_json_body()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        ai_visit_json_response(array('error' => 'Invalid JSON request.'), 400);
    }
    return $data;
}

function ai_visit_global($name, $default = '')
{
    return isset($GLOBALS[$name]) && $GLOBALS[$name] !== '' ? $GLOBALS[$name] : $default;
}

function ai_visit_extract_json($text)
{
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $best = null;
    $fallback = null;
    $length = strlen((string)$text);
    for ($start = 0; $start < $length; $start++) {
        if ($text[$start] !== '{') {
            continue;
        }
        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($pos = $start; $pos < $length; $pos++) {
            $char = $text[$pos];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $pos - $start + 1);
                    $decoded = json_decode($candidate, true);
                    if (is_array($decoded)) {
                        $fallback = $decoded;
                        if (isset($decoded['fields']) && is_array($decoded['fields'])) {
                            $best = $decoded;
                        }
                    }
                    break;
                }
            }
        }
    }

    return $best ?: $fallback;
}

function ai_visit_all_fields($mode)
{
    $plain = array('pl_main', 'pl_notes');
    $hpc = array(
        'hpc_pc', 'hpc_hpi', 'hpc_onset', 'hpc_char', 'hpc_rad', 'hpc_mod', 'hpc_assoc',
        'hpc_obhx', 'hpc_ros', 'hpc_pmhx', 'hpc_meds', 'hpc_fhx', 'hpc_ix_to_date',
        'ex_vitals', 'ex_gen', 'ex_resp', 'ex_cvs', 'ex_abdo', 'ex_pelvis', 'ex_cns',
        'hpc_dx', 'hpc_ddx', 'hpc_ix', 'hpc_rx', 'hpc_edu', 'hpc_fu', 'hpc_notes'
    );
    $soap = array(
        'soap_s', 'soap_vitals', 'soap_gen', 'soap_resp', 'soap_cvs', 'soap_abdo',
        'soap_pelvis', 'soap_cns', 'soap_ix', 'soap_adx', 'soap_ddx', 'soap_pix',
        'soap_rx', 'soap_edu', 'soap_fu', 'soap_notes'
    );

    if ($mode === 'hpc') {
        return $hpc;
    }
    if ($mode === 'soap') {
        return $soap;
    }
    return $plain;
}

function ai_visit_normalize_result($result, $mode, $providerUsed, $modelUsed, $phiScrubbed = false)
{
    $fields = isset($result['fields']) && is_array($result['fields']) ? $result['fields'] : array();
    $fields = ai_visit_flatten_common_fields($fields, $mode);
    $normalized = array();
    $multipleEncounterCollapsed = false;
    foreach (ai_visit_all_fields($mode) as $field) {
        $value = isset($fields[$field]) ? (string)$fields[$field] : '';
        if (ai_visit_contains_multiple_encounters($value)) {
            $value = ai_visit_latest_encounter_text($value);
            $multipleEncounterCollapsed = true;
        }
        $normalized[$field] = $value;
    }

    if ($mode === 'plain') {
        $main = trim($normalized['pl_main']);
        $notes = trim($normalized['pl_notes']);
        if ($notes !== '' && ($main === '' || str_word_count($main) < 8)) {
            $normalized['pl_main'] = $notes;
            $normalized['pl_notes'] = $main;
        }
        if ($multipleEncounterCollapsed && ai_visit_contains_multiple_encounters($normalized['pl_notes'])) {
            $normalized['pl_notes'] = '';
        }
    }

    $warnings = isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array();
    if ($mode === 'plain') {
        $warnings = ai_visit_filter_plain_warnings($warnings);
    }
    if ($multipleEncounterCollapsed) {
        array_unshift($warnings, 'Multiple encounter carryover was detected; earlier encounter sections were ignored. Review the current note before saving.');
    }

    return array(
        'mode' => $mode,
        'fields' => $normalized,
        'warnings' => $warnings,
        'confidence' => isset($result['confidence']) ? (string)$result['confidence'] : 'medium',
        'provider_used' => $providerUsed,
        'model_used' => $modelUsed,
        'phi_scrubbed' => $phiScrubbed,
    );
}

function ai_visit_contains_multiple_encounters($text)
{
    $text = (string)$text;
    if ($text === '') {
        return false;
    }
    if (preg_match('/VISIT\s+NOTE\s*[-—]\s*MULTIPLE\s+ENCOUNTERS/i', $text)) {
        return true;
    }
    preg_match_all('/^\s*(?:[-—]{2,}\s*)?ENCOUNTER\s+\d+\s*:/mi', $text, $matches);
    return count($matches[0]) > 1;
}

function ai_visit_latest_encounter_text($text)
{
    $text = trim((string)$text);
    $parts = preg_split('/(?=^\s*(?:[-—]{2,}\s*)?ENCOUNTER\s+\d+\s*:)/mi', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts || count($parts) < 2) {
        return $text;
    }

    $latest = trim($parts[count($parts) - 1]);
    $latest = preg_replace('/^\s*[-—]{2,}\s*/', '', $latest);
    $latest = preg_replace('/^\s*ENCOUNTER\s+\d+\s*:\s*/i', '', $latest);
    $latest = preg_replace('/^\s*VISIT\s+NOTE\s*[-—]\s*MULTIPLE\s+ENCOUNTERS\s*/i', '', $latest);
    $latest = preg_replace('/^\s*[-—]{2,}\s*/m', '', $latest);
    return trim($latest);
}

function ai_visit_filter_plain_warnings($warnings)
{
    $filtered = array();
    foreach ($warnings as $warning) {
        $warning = trim((string)$warning);
        if ($warning === '') {
            continue;
        }
        if (preg_match('/speech[- ]recognition|artifact|interpreted|inappropriate language|corrected/i', $warning)) {
            continue;
        }
        $filtered[] = $warning;
    }
    return $filtered;
}

function ai_visit_value_to_text($value)
{
    if (is_array($value)) {
        $parts = array();
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $v = ai_visit_value_to_text($v);
            }
            if (is_string($k)) {
                $parts[] = trim($k . ': ' . $v);
            } else {
                $parts[] = trim((string)$v);
            }
        }
        return implode("\n", array_filter($parts));
    }
    return trim((string)$value);
}

function ai_visit_clean_transcript($text)
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    $replacements = array(
        '/\bUm\b/i' => '',
        '/\bAnd\b/' => 'and',
        '/\bper prescribe\b/i' => 'prescribe',
        '/\bpob\b/i' => 'PO',
        '/\bjet otitis media\b/i' => 'otitis media',
        '/\bot\s+itis\b/i' => 'otitis',
        '/\band\s+the\s+plan\s+is\s*\.?/i' => '',
        '/\bprotect the planet(?:\s+of\s+momentum)?\.?/i' => '',
        '/\bso the\.?$/i' => '',
    );
    foreach ($replacements as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
    }
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text !== '') {
        $text = strtoupper(substr($text, 0, 1)) . substr($text, 1);
        if (!preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }
    }
    return $text;
}

function ai_visit_find_segment($text, $startPatterns, $endPatterns = array())
{
    $lower = strtolower($text);
    $start = null;
    foreach ($startPatterns as $pattern) {
        $pos = strpos($lower, $pattern);
        if ($pos !== false && ($start === null || $pos < $start)) {
            $start = $pos + strlen($pattern);
        }
    }
    if ($start === null) {
        return '';
    }

    $end = strlen($text);
    foreach ($endPatterns as $pattern) {
        $pos = strpos($lower, $pattern, $start);
        if ($pos !== false && $pos < $end) {
            $end = $pos;
        }
    }

    return ai_visit_clean_transcript(substr($text, $start, $end - $start));
}

function ai_visit_cut_before_patterns($text, $patterns)
{
    $lower = strtolower($text);
    $end = strlen($text);
    foreach ($patterns as $pattern) {
        $pos = strpos($lower, $pattern);
        if ($pos !== false && $pos < $end) {
            $end = $pos;
        }
    }
    return ai_visit_clean_transcript(substr($text, 0, $end));
}

function ai_visit_public_fallback_warning($warning)
{
    $warning = trim((string)$warning);
    if ($warning === '') {
        return '';
    }
    if (preg_match('/ollama|curl|operation timed out|timeout|http \d+|json|api|request failed/i', $warning)) {
        return 'Local AI unavailable; draft generated from transcript only. Review before saving.';
    }
    return $warning;
}

function ai_visit_fallback_field($text)
{
    $text = ai_visit_clean_transcript($text);
    $text = preg_replace('/\bthe plan is\s*\.?/i', '', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/^is\s+/i', '', $text);
    $text = preg_replace('/\b(?:and|and to|to)\.?$/i', '', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (preg_match('/protect the planet|of momentum|^so the\.?$/i', $text)) {
        return '';
    }
    return $text;
}

function ai_visit_clean_fallback_fields(&$fields)
{
    foreach ($fields as $field => $value) {
        $fields[$field] = ai_visit_fallback_field($value);
    }
}

function ai_visit_fallback_result($transcript, $mode, $warning)
{
    $clean = ai_visit_clean_transcript($transcript);
    $publicWarning = ai_visit_public_fallback_warning($warning);
    $fields = array();
    foreach (ai_visit_all_fields($mode) as $field) {
        $fields[$field] = '';
    }

    if ($mode === 'plain') {
        $fields['pl_main'] = $clean;
    } elseif ($mode === 'soap') {
        $fields['soap_s'] = ai_visit_find_segment($clean, array('presented with ', 'complains of ', 'came with ', 'patient '), array('on examination', 'exam ', 'impression ', 'assessment ', 'diagnosis ', 'prescribe ', 'started ', 'admit ', 'follow'));
        if ($fields['soap_s'] === '') {
            $fields['soap_s'] = ai_visit_cut_before_patterns($clean, array(' so the diagnosis', ' diagnosis is ', ' impression ', ' assessment '));
        }
        $fields['soap_vitals'] = ai_visit_find_segment($clean, array('vitals ', 'vital signs '), array('on examination', 'exam ', 'impression ', 'assessment ', 'diagnosis ', 'prescribe ', 'started ', 'admit ', 'follow'));
        $fields['soap_abdo'] = ai_visit_find_segment($clean, array('on examination ', 'exam ', 'abdomen ', 'abdominal exam '), array('impression ', 'assessment ', 'diagnosis ', 'prescribe ', 'started ', 'admit ', 'follow'));
        $fields['soap_adx'] = ai_visit_find_segment($clean, array('impression was ', 'impression ', 'assessment ', 'diagnosis '), array(' plan ', ' the plan ', 'prescribe ', 'started ', 'treat ', 'admit ', 'follow'));
        $fields['soap_rx'] = ai_visit_find_segment($clean, array('prescribe ', 'started ', 'treated with ', 'placed on '), array('follow ', 'review ', 'admit '));
        $fields['soap_fu'] = ai_visit_find_segment($clean, array('follow ', 'review ', 'see her again ', 'safety net ', 'advice '));
    } else {
        $fields['hpc_pc'] = ai_visit_find_segment($clean, array('presented with ', 'complains of ', 'came with ', 'patient '), array('on examination', 'exam ', 'impression ', 'assessment ', 'diagnosis ', 'prescribe ', 'started ', 'admit ', 'follow'));
        if ($fields['hpc_pc'] === '') {
            $fields['hpc_pc'] = ai_visit_cut_before_patterns($clean, array(' so the diagnosis', ' diagnosis is ', ' impression ', ' assessment '));
        }
        $fields['hpc_hpi'] = ai_visit_cut_before_patterns($clean, array(' on examination', ' exam ', ' impression ', ' assessment ', ' diagnosis ', ' prescribe ', ' started ', ' admit ', ' follow '));
        $fields['ex_vitals'] = ai_visit_find_segment($clean, array('vitals ', 'vital signs '), array('on examination', 'exam ', 'impression ', 'assessment ', 'diagnosis ', 'prescribe ', 'started ', 'admit ', 'follow'));
        $fields['ex_abdo'] = ai_visit_find_segment($clean, array('on examination ', 'exam ', 'abdomen ', 'abdominal exam '), array('impression ', 'assessment ', 'diagnosis ', 'prescribe ', 'started ', 'admit ', 'follow'));
        $fields['hpc_dx'] = ai_visit_find_segment($clean, array('impression was ', 'impression ', 'assessment ', 'diagnosis '), array(' plan ', ' the plan ', 'prescribe ', 'started ', 'treat ', 'admit ', 'follow'));
        $fields['hpc_rx'] = ai_visit_find_segment($clean, array('prescribe ', 'started ', 'treated with ', 'placed on '), array('follow ', 'review ', 'admit '));
        $fields['hpc_fu'] = ai_visit_find_segment($clean, array('follow ', 'review ', 'see her again ', 'safety net ', 'advice '));
    }
    ai_visit_clean_fallback_fields($fields);

    return array(
        'mode' => $mode,
        'fields' => $fields,
        'warnings' => $publicWarning !== '' ? array($publicWarning) : array(),
        'confidence' => 'low',
    );
}

function ai_visit_flatten_common_fields($fields, $mode)
{
    if ($mode === 'soap') {
        if (!empty($fields['vitals']) && empty($fields['soap_vitals'])) {
            $fields['soap_vitals'] = ai_visit_value_to_text($fields['vitals']);
        }
        if (!empty($fields['exam']) && is_array($fields['exam'])) {
            if (!empty($fields['exam']['abdomen']) && empty($fields['soap_abdo'])) {
                $fields['soap_abdo'] = ai_visit_value_to_text($fields['exam']['abdomen']);
            }
            if (!empty($fields['exam']['pelvis']) && empty($fields['soap_pelvis'])) {
                $fields['soap_pelvis'] = ai_visit_value_to_text($fields['exam']['pelvis']);
            }
            if (!empty($fields['exam']['general']) && empty($fields['soap_gen'])) {
                $fields['soap_gen'] = ai_visit_value_to_text($fields['exam']['general']);
            }
        }
        foreach (array('ix' => 'soap_pix', 'investigations' => 'soap_pix', 'rx' => 'soap_rx', 'treatment' => 'soap_rx', 'fu' => 'soap_fu', 'followup' => 'soap_fu', 'assessment' => 'soap_adx', 'diagnosis' => 'soap_adx') as $from => $to) {
            if (!empty($fields[$from]) && empty($fields[$to])) {
                $fields[$to] = ai_visit_value_to_text($fields[$from]);
            }
        }
    } elseif ($mode === 'hpc') {
        foreach (array('hpi' => 'hpc_hpi', 'history' => 'hpc_hpi', 'history_presenting_complaint' => 'hpc_hpi', 'history_of_presenting_complaint' => 'hpc_hpi', 'review_of_systems' => 'hpc_ros', 'systems_review' => 'hpc_ros', 'ros' => 'hpc_ros', 'family_history' => 'hpc_fhx', 'fhx' => 'hpc_fhx', 'investigations_to_date' => 'hpc_ix_to_date', 'prior_investigations' => 'hpc_ix_to_date', 'previous_investigations' => 'hpc_ix_to_date', 'results_to_date' => 'hpc_ix_to_date') as $from => $to) {
            if (!empty($fields[$from]) && empty($fields[$to])) {
                $fields[$to] = ai_visit_value_to_text($fields[$from]);
            }
        }
        if (!empty($fields['vitals']) && empty($fields['ex_vitals'])) {
            $fields['ex_vitals'] = ai_visit_value_to_text($fields['vitals']);
        }
        if (!empty($fields['exam']) && is_array($fields['exam'])) {
            if (!empty($fields['exam']['abdomen']) && empty($fields['ex_abdo'])) {
                $fields['ex_abdo'] = ai_visit_value_to_text($fields['exam']['abdomen']);
            }
            if (!empty($fields['exam']['pelvis']) && empty($fields['ex_pelvis'])) {
                $fields['ex_pelvis'] = ai_visit_value_to_text($fields['exam']['pelvis']);
            }
            if (!empty($fields['exam']['general']) && empty($fields['ex_gen'])) {
                $fields['ex_gen'] = ai_visit_value_to_text($fields['exam']['general']);
            }
        }
        foreach (array('ix' => 'hpc_ix', 'investigations' => 'hpc_ix', 'rx' => 'hpc_rx', 'treatment' => 'hpc_rx', 'fu' => 'hpc_fu', 'followup' => 'hpc_fu', 'assessment' => 'hpc_dx', 'diagnosis' => 'hpc_dx') as $from => $to) {
            if (!empty($fields[$from]) && empty($fields[$to])) {
                $fields[$to] = ai_visit_value_to_text($fields[$from]);
            }
        }
    }
    return $fields;
}

function ai_visit_field_guide($mode)
{
    if ($mode !== 'hpc') {
        return '';
    }
    return "HPC field guide: hpc_pc = short presenting complaint only; hpc_hpi = detailed free-text history of presenting complaint; hpc_onset/hpc_char/hpc_rad/hpc_mod/hpc_assoc = optional symptom detail fields; hpc_ros = review of systems; hpc_fhx = family history; hpc_ix_to_date = investigations/results already completed before this visit; hpc_ix = investigations requested or planned from this visit.";
}

function ai_visit_ollama_generate($prompt)
{
    $endpoint = rtrim(ai_visit_global('ai_dictation_ollama_endpoint', 'http://127.0.0.1:11434'), '/');
    if ($endpoint === '') {
        $endpoint = 'http://127.0.0.1:11434';
    }
    $model = ai_visit_global('ai_dictation_ollama_model', 'clinicalscribe');
    if ($model === 'custom') {
        $model = ai_visit_global('ai_dictation_ollama_custom_model', 'clinicalscribe');
    }
    if ($model === '') {
        $model = 'clinicalscribe';
    }

    $payload = json_encode(array(
        'model' => $model,
        'prompt' => $prompt,
        'format' => 'json',
        'stream' => false,
        'options' => array(
            'temperature' => 0.1,
            'top_p' => 0.85,
            'num_ctx' => 3072,
            'num_predict' => 800
        )
    ));

    $ch = curl_init($endpoint . '/api/generate');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return array('', $model, 'Ollama request failed: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        return array('', $model, 'Ollama returned HTTP ' . $status . ' for model "' . $model . '": ' . substr((string)$response, 0, 300));
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['response'])) {
        return array('', $model, 'Unexpected Ollama response.');
    }

    return array($decoded['response'], $model, '');
}

function ai_visit_openai_model()
{
    $model = ai_visit_global('ai_dictation_openai_model', 'gpt-4o-mini');
    if ($model === 'custom') {
        $model = ai_visit_global('ai_dictation_openai_custom_model', 'gpt-4o-mini');
    }
    return $model !== '' ? $model : 'gpt-4o-mini';
}

function ai_visit_openai_generate($prompt)
{
    $apiKey = ai_visit_global('ai_dictation_openai_api_key', '');
    if ($apiKey === '') {
        ai_visit_json_response(array('error' => 'OpenAI API key is not configured in Global Settings > Voice Dictation.'), 400);
    }

    $model = ai_visit_openai_model();
    $payload = json_encode(array(
        'model' => $model,
        'temperature' => 0.1,
        'response_format' => array('type' => 'json_object'),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => ai_visit_system_prompt()
            ),
            array('role' => 'user', 'content' => $prompt)
        )
    ));

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        ai_visit_json_response(array('error' => 'OpenAI request failed: ' . $error), 502);
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = 'OpenAI returned HTTP ' . $status . '.';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message .= ' ' . $decoded['error']['message'];
        }
        ai_visit_json_response(array('error' => $message), 502);
    }

    if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
        ai_visit_json_response(array('error' => 'Unexpected OpenAI response.'), 502);
    }

    return array($decoded['choices'][0]['message']['content'], $model);
}

function ai_visit_claude_model()
{
    $model = ai_visit_global('ai_dictation_claude_model', 'claude-haiku');
    if ($model === 'custom') {
        return ai_visit_global('ai_dictation_claude_custom_model', 'claude-haiku-4-5-20251001');
    }
    $map = array(
        'claude-haiku' => 'claude-haiku-4-5-20251001',
        'claude-sonnet' => 'claude-sonnet-4-6',
        'claude-3-5-haiku' => 'claude-haiku-4-5-20251001',
        'claude-3-5-sonnet' => 'claude-sonnet-4-6',
    );
    return isset($map[$model]) ? $map[$model] : $model;
}

function ai_visit_claude_generate($prompt)
{
    $apiKey = ai_visit_global('ai_dictation_claude_api_key', '');
    if ($apiKey === '') {
        ai_visit_json_response(array('error' => 'Claude API key is not configured in Global Settings > Voice Dictation.'), 400);
    }

    $model = ai_visit_claude_model();
    $payload = json_encode(array(
        'model' => $model,
        'max_tokens' => 900,
        'temperature' => 0.1,
        'system' => ai_visit_system_prompt(),
        'messages' => array(
            array('role' => 'user', 'content' => $prompt)
        )
    ));

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        ai_visit_json_response(array('error' => 'Claude request failed: ' . $error), 502);
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = 'Claude returned HTTP ' . $status . '.';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message .= ' ' . $decoded['error']['message'];
        }
        ai_visit_json_response(array('error' => $message), 502);
    }

    if (!is_array($decoded) || empty($decoded['content']) || !is_array($decoded['content'])) {
        ai_visit_json_response(array('error' => 'Unexpected Claude response.'), 502);
    }

    $text = '';
    foreach ($decoded['content'] as $block) {
        if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
            $text .= $block['text'];
        }
    }
    if ($text === '') {
        ai_visit_json_response(array('error' => 'Claude returned no text content.'), 502);
    }

    return array($text, $model);
}

function ai_visit_gemma_model()
{
    $model = ai_visit_global('ai_dictation_gemma_model', 'gemini-2.5-flash');
    return $model !== '' ? $model : 'gemini-2.5-flash';
}

function ai_visit_encode_path($path)
{
    $parts = explode('/', trim((string)$path, '/'));
    $encoded = array();
    foreach ($parts as $part) {
        if ($part !== '') {
            $encoded[] = rawurlencode($part);
        }
    }
    return implode('/', $encoded);
}

function ai_visit_gemma_payload($prompt)
{
    return json_encode(array(
        'contents' => array(
            array(
                'role' => 'user',
                'parts' => array(
                    array('text' => ai_visit_system_prompt() . "\n\nTask:\n" . $prompt)
                )
            )
        ),
        'generationConfig' => array(
            'temperature' => 0.1,
            'maxOutputTokens' => 1400,
            'responseMimeType' => 'application/json'
        )
    ));
}

function ai_visit_gemma_response_text($decoded)
{
    $text = '';
    if (!is_array($decoded) || empty($decoded['candidates']) || !is_array($decoded['candidates'])) {
        return '';
    }
    foreach ($decoded['candidates'] as $candidate) {
        if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
            foreach ($candidate['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }
        if ($text !== '') {
            break;
        }
    }
    return $text;
}

function ai_visit_gemma_send($url, $payload)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array($response, $status, $errno, $error, json_decode($response, true));
}

function ai_visit_gemma_google_url($model, $apiKey)
{
    $model = strpos($model, '/') !== false ? basename($model) : $model;
    return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
}

function ai_visit_gemma_vertex_url($model, $apiKey)
{
    $modelPath = strpos($model, '/') !== false ? $model : 'publishers/google/models/' . $model;
    return 'https://aiplatform.googleapis.com/v1/' . ai_visit_encode_path($modelPath) . ':generateContent?key=' . rawurlencode($apiKey);
}

function ai_visit_gemma_try_google($apiKey, $models, $payload, $lastMessage)
{
    foreach ($models as $model) {
        list($response, $status, $errno, $error, $decoded) = ai_visit_gemma_send(ai_visit_gemma_google_url($model, $apiKey), $payload);
        if ($errno) {
            $lastMessage = 'Google Gemma request failed: ' . $error;
            continue;
        }
        if ($status >= 200 && $status < 300) {
            $text = ai_visit_gemma_response_text($decoded);
            if ($text !== '') {
                if (ai_visit_extract_json($text)) {
                    return array($text, $model, '');
                }
                $lastMessage = 'Google AI model ' . $model . ' returned text that was not valid JSON.';
                continue;
            }
            $lastMessage = 'Google Gemma returned no text content.';
            continue;
        }
        $lastMessage = 'Google Gemma returned HTTP ' . $status . '.';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $lastMessage .= ' ' . $decoded['error']['message'];
        } elseif ((string)$response !== '') {
            $lastMessage .= ' ' . substr((string)$response, 0, 300);
        }
    }
    return array('', $models[0], $lastMessage);
}

function ai_visit_gemma_generate($prompt)
{
    $apiKey = ai_visit_global('ai_dictation_gemma_api_key', '');
    if ($apiKey === '') {
        ai_visit_json_response(array('error' => 'Google AI API key is not configured in Global Settings > Voice Dictation.'), 400);
    }

    $model = ai_visit_gemma_model();
    $fallbackModels = array_values(array_unique(array($model, 'gemini-2.5-flash', 'gemma-4-26b-a4b-it')));
    $payload = ai_visit_gemma_payload($prompt);

    list($text, $usedModel, $lastMessage) = ai_visit_gemma_try_google($apiKey, $fallbackModels, $payload, 'Google AI request failed.');
    if ($text !== '') {
        return array($text, $usedModel);
    }

    ai_visit_json_response(array('error' => $lastMessage), 502);
}

function ai_visit_system_prompt()
{
    return 'You format clinician dictation for OpenEMR AI Visit Forms. Return only valid JSON. Act like a clinical scribe: infer the intended medical phrase from context when speech recognition is obviously wrong, and write the corrected clinical note without mentioning the speech-recognition error. Do not invent facts. Do not include transcription-audit warnings. Warn only for true clinical uncertainty, missing critical information, or a safety issue that must be reviewed. For plain mode, put the complete useful clinical note in pl_main and reserve pl_notes for brief residual comments only. Never produce a multi-encounter note, Encounter 1/2/3 sections, or VISIT NOTE - MULTIPLE ENCOUNTERS. If text appears to include more than one visit, format only the latest/current encounter and ignore earlier carryover.';
}

function ai_visit_scrub_phi($text)
{
    $scrubbed = (string)$text;
    $scrubbed = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[email]', $scrubbed);
    $scrubbed = preg_replace('/\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/', '[phone]', $scrubbed);
    $scrubbed = preg_replace('/\b(?:DOB|date of birth)\s*[:\-]?\s*\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}\b/i', '[dob]', $scrubbed);
    $scrubbed = preg_replace('/\b(?:MRN|medical record number)\s*[:#\-]?\s*[A-Z0-9\-]+\b/i', '[mrn]', $scrubbed);
    return $scrubbed;
}

$request = ai_visit_read_json_body();
$mode = isset($request['mode']) ? strtolower(trim($request['mode'])) : 'plain';
if (!in_array($mode, array('plain', 'hpc', 'soap'), true)) {
    $mode = 'plain';
}

$provider = isset($request['provider']) ? strtolower(trim($request['provider'])) : 'local';
if ($provider === '') {
    $provider = ai_visit_global('ai_dictation_provider', 'local');
}
if (!in_array($provider, array('local', 'gpt', 'openai', 'claude', 'gemma'), true)) {
    $provider = 'local';
}
if ($provider === 'openai') {
    $provider = 'gpt';
}

$transcript = isset($request['transcript']) ? trim((string)$request['transcript']) : '';
if ($transcript === '') {
    ai_visit_json_response(array('error' => 'No transcript or note text was provided.'), 400);
}

$existing = isset($request['existing_fields']) && is_array($request['existing_fields']) ? $request['existing_fields'] : array();
$phiScrubbed = false;
if (($provider === 'gpt' || $provider === 'claude' || $provider === 'gemma') && !empty($GLOBALS['ai_dictation_phi_scrub'])) {
    $transcript = ai_visit_scrub_phi($transcript);
    $phiScrubbed = true;
}

$exactFields = implode(', ', ai_visit_all_fields($mode));
$fieldGuide = ai_visit_field_guide($mode);
$plainInstruction = $mode === 'plain'
    ? "Plain mode: write the full corrected clinical note in pl_main. Do not put the diagnosis alone in pl_main. Use pl_notes only for brief residual comments. Do not list speech-recognition corrections in warnings."
    : "";
$prompt = "Mode: " . $mode . "\nTranscript: " . $transcript . "\nFields: " . $exactFields . "\n" . $fieldGuide . "\n" . $plainInstruction . "\nCurrent encounter only: do not create multiple encounter sections. If the transcript contains older visits or more than one encounter, use only the latest/current encounter.\nReturn JSON only as {\"mode\":\"" . $mode . "\",\"fields\":{},\"warnings\":[],\"confidence\":\"high|medium|low\"}. Every field key must exist. Empty string if unknown. Correct obvious speech artifacts from context. No invented facts. Warnings only for true clinical uncertainty or safety-critical ambiguity.";

if ($provider === 'local') {
    list($raw, $modelUsed, $localError) = ai_visit_ollama_generate($prompt);
    $providerUsed = 'local';
    if ($localError !== '') {
        $fallback = ai_visit_fallback_result($transcript, $mode, $localError);
        ai_visit_json_response(ai_visit_normalize_result($fallback, $mode, $providerUsed, $modelUsed, $phiScrubbed));
    }
} elseif ($provider === 'gpt') {
    list($raw, $modelUsed) = ai_visit_openai_generate($prompt);
    $providerUsed = 'gpt';
} elseif ($provider === 'claude') {
    list($raw, $modelUsed) = ai_visit_claude_generate($prompt);
    $providerUsed = 'claude';
} elseif ($provider === 'gemma') {
    list($raw, $modelUsed) = ai_visit_gemma_generate($prompt);
    $providerUsed = 'gemma';
} else {
    ai_visit_json_response(array('error' => 'Provider "' . $provider . '" is not available. Select Local Ollama, GPT API, Claude API, or Google AI.'), 400);
}

$parsed = ai_visit_extract_json($raw);
if (!$parsed) {
    if ($providerUsed === 'local') {
        $fallback = ai_visit_fallback_result($transcript, $mode, 'Ollama returned text that could not be parsed as JSON.');
        ai_visit_json_response(ai_visit_normalize_result($fallback, $mode, $providerUsed, $modelUsed, $phiScrubbed));
    }
    ai_visit_json_response(array(
        'error' => 'The selected AI provider did not return valid JSON.',
        'raw_response' => $raw
    ), 502);
}

ai_visit_json_response(ai_visit_normalize_result($parsed, $mode, $providerUsed, $modelUsed, $phiScrubbed));
