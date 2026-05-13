<?php
/**
 * AI Visit Forms shared renderer.
 *
 * @package OpenEMR
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc");

use OpenEMR\Core\Header;

function ai_visit_forms_safe_json($value)
{
    if (!$value) {
        return '{}';
    }

    json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return '{}';
    }

    return $value;
}

function ai_visit_forms_global($name, $default = '')
{
    return isset($GLOBALS[$name]) && $GLOBALS[$name] !== '' ? $GLOBALS[$name] : $default;
}

function ai_visit_forms_normalize_provider($provider)
{
    $provider = strtolower(trim((string)$provider));
    if ($provider === 'openai') {
        $provider = 'gpt';
    }
    if (!in_array($provider, array('local', 'gpt', 'claude', 'gemma'), true)) {
        return 'local';
    }
    return $provider;
}

function ai_visit_forms_trim($value)
{
    return trim((string)$value);
}

function ai_visit_forms_name($parts)
{
    $clean = array();
    foreach ($parts as $part) {
        $part = ai_visit_forms_trim($part);
        if ($part !== '') {
            $clean[] = $part;
        }
    }
    return implode(' ', $clean);
}

function ai_visit_forms_date($date)
{
    $date = ai_visit_forms_trim($date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d M Y', $time) : $date;
}

function ai_visit_forms_facility_has_address($facility)
{
    if (!is_array($facility)) {
        return false;
    }
    foreach (array('street', 'city', 'state', 'postal_code', 'country_code') as $field) {
        if (ai_visit_forms_trim($facility[$field] ?? '') !== '') {
            return true;
        }
    }
    return false;
}

function ai_visit_forms_facility_payload($facility)
{
    if (!is_array($facility)) {
        return array();
    }
    return array(
        'id' => isset($facility['id']) ? (int)$facility['id'] : 0,
        'name' => ai_visit_forms_trim($facility['name'] ?? ''),
        'street' => ai_visit_forms_trim($facility['street'] ?? ''),
        'city' => ai_visit_forms_trim($facility['city'] ?? ''),
        'state' => ai_visit_forms_trim($facility['state'] ?? ''),
        'postal_code' => ai_visit_forms_trim($facility['postal_code'] ?? ''),
        'country_code' => ai_visit_forms_trim($facility['country_code'] ?? ''),
        'phone' => ai_visit_forms_trim($facility['phone'] ?? ''),
        'fax' => ai_visit_forms_trim($facility['fax'] ?? ''),
        'email' => ai_visit_forms_trim($facility['email'] ?? ''),
    );
}

function ai_visit_forms_facilities()
{
    $facilities = array();
    $res = sqlStatement(
        "SELECT id, name, street, city, state, postal_code, country_code, phone, fax, email " .
        "FROM facility ORDER BY primary_business_entity DESC, billing_location DESC, service_location DESC, name, id"
    );
    while ($row = sqlFetchArray($res)) {
        $facility = ai_visit_forms_facility_payload($row);
        if (($facility['id'] ?? 0) > 0) {
            $facilities[] = $facility;
        }
    }
    return $facilities;
}

function ai_visit_forms_logo_url($rootdir = '')
{
    $siteId = $_SESSION['site_id'] ?? 'default';
    $webroot = preg_replace('#/interface$#', '', (string)$rootdir);
    if ($webroot === '') {
        $webroot = '/openemr';
    }
    $siteDir = isset($GLOBALS['OE_SITE_DIR']) && $GLOBALS['OE_SITE_DIR'] !== ''
        ? $GLOBALS['OE_SITE_DIR']
        : dirname(__DIR__, 3) . '/sites/' . $siteId;
    $candidates = array(
        array($siteDir . '/images/practice_logo.gif', $webroot . '/sites/' . rawurlencode($siteId) . '/images/practice_logo.gif'),
        array($siteDir . '/practice_logo.gif', $webroot . '/sites/' . rawurlencode($siteId) . '/practice_logo.gif'),
        array($siteDir . '/images/logo_1.png', $webroot . '/sites/' . rawurlencode($siteId) . '/images/logo_1.png'),
        array($siteDir . '/images/logo_2.png', $webroot . '/sites/' . rawurlencode($siteId) . '/images/logo_2.png'),
    );
    foreach ($candidates as $candidate) {
        if (is_readable($candidate[0]) && filesize($candidate[0]) > 0) {
            return $candidate[1];
        }
    }
    return '';
}

function ai_visit_forms_referring_providers()
{
    $providers = array();
    $sql = "SELECT u.id, u.title, u.fname, u.mname, u.lname, u.suffix, u.specialty, u.organization, " .
        "u.street, u.streetb, u.city, u.state, u.zip, u.phone, u.phonew1, u.fax, u.email, u.abook_type, " .
        "a.line1 AS addr_line1, a.line2 AS addr_line2, a.city AS addr_city, a.state AS addr_state, a.zip AS addr_zip " .
        "FROM users u LEFT JOIN addresses a ON a.foreign_id = u.id " .
        "WHERE u.active = 1 AND (COALESCE(u.fname, '') <> '' OR COALESCE(u.lname, '') <> '' OR COALESCE(u.organization, '') <> '') " .
        "ORDER BY u.lname, u.fname, u.organization LIMIT 500";
    $res = sqlStatement($sql);
    while ($row = sqlFetchArray($res)) {
        $title = ai_visit_forms_trim($row['title'] ?? '');
        $name = ai_visit_forms_name(array($title, $row['fname'] ?? '', $row['mname'] ?? '', $row['lname'] ?? '', $row['suffix'] ?? ''));
        if ($name === '') {
            $name = ai_visit_forms_trim($row['organization'] ?? '');
        }
        if ($name === '') {
            continue;
        }
        $street = ai_visit_forms_trim(($row['street'] ?? '') ?: ($row['addr_line1'] ?? ''));
        $street2 = ai_visit_forms_trim(($row['streetb'] ?? '') ?: ($row['addr_line2'] ?? ''));
        $city = ai_visit_forms_trim(($row['city'] ?? '') ?: ($row['addr_city'] ?? ''));
        $state = ai_visit_forms_trim(($row['state'] ?? '') ?: ($row['addr_state'] ?? ''));
        $zip = ai_visit_forms_trim(($row['zip'] ?? '') ?: ($row['addr_zip'] ?? ''));
        $practice = ai_visit_forms_trim($row['organization'] ?? '');
        $address = array();
        if ($street !== '') {
            $address[] = $street;
        }
        if ($street2 !== '') {
            $address[] = $street2;
        }
        $cityLine = ai_visit_forms_name(array($city, $state, $zip));
        if ($cityLine !== '') {
            $address[] = $cityLine;
        }
        $providers[] = array(
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'salutation_name' => ai_visit_forms_trim($row['lname'] ?? ''),
            'practice' => $practice,
            'address' => implode("\n", $address),
            'phone' => ai_visit_forms_trim(($row['phonew1'] ?? '') ?: ($row['phone'] ?? '')),
            'fax' => ai_visit_forms_trim($row['fax'] ?? ''),
            'email' => ai_visit_forms_trim($row['email'] ?? ''),
            'specialty' => ai_visit_forms_trim($row['specialty'] ?? ''),
            'abook_type' => ai_visit_forms_trim($row['abook_type'] ?? ''),
        );
    }
    return $providers;
}

function ai_visit_forms_letter_context($pid, $encounter, $rootdir = '')
{
    $context = array(
        'provider' => array(),
        'facility' => array(),
        'facilities' => array(),
        'patient' => array(),
        'referring_providers' => array(),
        'logo_url' => ai_visit_forms_logo_url($rootdir),
    );

    $patient = $pid ? sqlQuery(
        "SELECT fname, mname, lname, DOB FROM patient_data WHERE pid = ?",
        array($pid)
    ) : array();
    if (is_array($patient)) {
        $context['patient'] = array(
            'name' => ai_visit_forms_name(array($patient['fname'] ?? '', $patient['mname'] ?? '', $patient['lname'] ?? '')),
            'dob' => ai_visit_forms_date($patient['DOB'] ?? ''),
        );
    }

    $encounterRow = ($pid && $encounter) ? sqlQuery(
        "SELECT provider_id, facility_id FROM form_encounter WHERE pid = ? AND encounter = ? ORDER BY id DESC LIMIT 1",
        array($pid, $encounter)
    ) : array();

    $providerId = 0;
    if (is_array($encounterRow) && !empty($encounterRow['provider_id'])) {
        $providerId = (int)$encounterRow['provider_id'];
    } elseif (!empty($_SESSION['authUserID'])) {
        $providerId = (int)$_SESSION['authUserID'];
    }

    $provider = $providerId ? sqlQuery(
        "SELECT id, title, fname, mname, lname, suffix, specialty, facility_id, phone, phonew1, fax, email FROM users WHERE id = ?",
        array($providerId)
    ) : array();

    $facilityId = 0;
    if (is_array($encounterRow) && !empty($encounterRow['facility_id'])) {
        $facilityId = (int)$encounterRow['facility_id'];
    } elseif (is_array($provider) && !empty($provider['facility_id'])) {
        $facilityId = (int)$provider['facility_id'];
    }

    if (is_array($provider)) {
        $title = ai_visit_forms_trim($provider['title'] ?? '');
        if ($title === '') {
            $title = 'Dr.';
        }
        $name = ai_visit_forms_name(array($title, $provider['fname'] ?? '', $provider['mname'] ?? '', $provider['lname'] ?? '', $provider['suffix'] ?? ''));
        $context['provider'] = array(
            'id' => $providerId,
            'name' => $name,
            'specialty' => ai_visit_forms_trim($provider['specialty'] ?? ''),
            'phone' => ai_visit_forms_trim(($provider['phonew1'] ?? '') ?: ($provider['phone'] ?? '')),
            'fax' => ai_visit_forms_trim($provider['fax'] ?? ''),
            'email' => ai_visit_forms_trim($provider['email'] ?? ''),
        );
    }

    $facility = $facilityId ? sqlQuery(
        "SELECT id, name, street, city, state, postal_code, country_code, phone, fax, email FROM facility WHERE id = ?",
        array($facilityId)
    ) : array();
    if (!is_array($facility) || empty($facility['id'])) {
        $facility = sqlQuery(
            "SELECT id, name, street, city, state, postal_code, country_code, phone, fax, email FROM facility ORDER BY primary_business_entity DESC, id ASC LIMIT 1"
        );
    }
    if (is_array($facility) && !ai_visit_forms_facility_has_address($facility)) {
        $addressFacility = sqlQuery(
            "SELECT id, name, street, city, state, postal_code, country_code, phone, fax, email FROM facility " .
            "WHERE COALESCE(street, '') <> '' OR COALESCE(city, '') <> '' OR COALESCE(state, '') <> '' OR COALESCE(postal_code, '') <> '' " .
            "ORDER BY primary_business_entity DESC, billing_location DESC, service_location DESC, id ASC LIMIT 1"
        );
        if (is_array($addressFacility)) {
            $facility = $addressFacility;
        }
    }
    if (is_array($facility)) {
        $context['facility'] = ai_visit_forms_facility_payload($facility);
    }

    $context['facilities'] = ai_visit_forms_facilities();
    $context['referring_providers'] = ai_visit_forms_referring_providers();

    return $context;
}

function ai_visit_forms_model_context($provider)
{
    $ollamaModel = ai_visit_forms_global('ai_dictation_ollama_model', 'clinicalscribe');
    if ($ollamaModel === 'custom') {
        $ollamaModel = ai_visit_forms_global('ai_dictation_ollama_custom_model', 'clinicalscribe');
    }
    $openaiModel = ai_visit_forms_global('ai_dictation_openai_model', 'gpt-4o-mini');
    if ($openaiModel === 'custom') {
        $openaiModel = ai_visit_forms_global('ai_dictation_openai_custom_model', 'gpt-4o-mini');
    }
    $claudeSetting = ai_visit_forms_global('ai_dictation_claude_model', 'claude-haiku');
    $claudeCustom = ai_visit_forms_global('ai_dictation_claude_custom_model', '');
    $claudeLabels = array(
        'claude-haiku' => 'Claude API — Haiku 4.5',
        'claude-sonnet' => 'Claude API — Sonnet 4.6',
        'custom' => 'Claude API — ' . ($claudeCustom !== '' ? $claudeCustom : 'Custom'),
    );
    $gemmaModel = ai_visit_forms_global('ai_dictation_gemma_model', 'gemini-2.5-flash');

    return array(
        'active_provider' => $provider,
        'local' => array('model' => $ollamaModel, 'label' => 'Local — Ollama (' . $ollamaModel . ')'),
        'gpt' => array('model' => $openaiModel, 'label' => 'GPT API — OpenAI (' . $openaiModel . ')'),
        'claude' => array('model' => $claudeSetting === 'custom' ? $claudeCustom : $claudeSetting, 'label' => isset($claudeLabels[$claudeSetting]) ? $claudeLabels[$claudeSetting] : 'Claude API — ' . $claudeSetting),
        'gemma' => array('model' => $gemmaModel, 'label' => 'Google AI — Gemini/Gemma (' . $gemmaModel . ')'),
    );
}

function ai_visit_forms_render($mode, $id = null, $record = array())
{
    global $rootdir, $pid, $encounter;

    $payload = ai_visit_forms_safe_json($record['payload_json'] ?? '');
    $dictationMode = $record['dictation_mode'] ?? 'plain';
    $provider = ai_visit_forms_normalize_provider(ai_visit_forms_global('ai_dictation_provider', $record['llm_provider'] ?? 'local'));
    $letterText = '';
    $letterContext = ai_visit_forms_letter_context($pid, $encounter, $rootdir);
    $modelContext = ai_visit_forms_model_context($provider);
    $sttEngine = ai_visit_forms_global('ai_dictation_stt_engine', 'chrome');
    $dictationLanguage = ai_visit_forms_global('ai_dictation_language', 'en-US');
    $storageKey = 'ai_visit_forms_' . ($pid ?: 'patient') . '_' . ($encounter ?: 'encounter') . '_' . ($id ?: 'new');
    $action = $rootdir . "/forms/ai_visit_forms/save.php?mode=" . rawurlencode($mode);
    if ($id !== null) {
        $action .= "&id=" . rawurlencode($id);
    }
    ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("Advance Visit Form"); ?></title>
    <?php Header::setupHeader(); ?>
    <link rel="stylesheet" href="<?php echo $rootdir; ?>/forms/ai_visit_forms/style.css?v=46">
</head>
<body class="body_top">
<form id="ai-visit-form" method="post" action="<?php echo attr($action); ?>" onsubmit="return top.restoreSession()">
    <input type="hidden" name="payload_json" id="payload_json" value="">
    <input type="hidden" name="dictation_mode" id="dictation_mode" value="<?php echo attr($dictationMode); ?>">
    <input type="hidden" name="llm_provider" id="llm_provider" value="<?php echo attr($provider); ?>">
    <input type="hidden" name="letter_text" id="letter_text_value" value="<?php echo attr($letterText); ?>">
    <script>
      window.AI_VISIT_FORM_PAYLOAD = <?php echo $payload; ?>;
      window.AI_VISIT_FORM_STORAGE_KEY = <?php echo json_encode($storageKey); ?>;
      window.AI_VISIT_FORMAT_URL = <?php echo json_encode($rootdir . "/forms/ai_visit_forms/format.php"); ?>;
      window.AI_VISIT_TRANSCRIBE_URL = <?php echo json_encode($rootdir . "/forms/ai_visit_forms/transcribe.php"); ?>;
      window.AI_VISIT_DEFAULT_PROVIDER = <?php echo json_encode($provider); ?>;
      window.AI_VISIT_MODEL_CONTEXT = <?php echo json_encode($modelContext, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      window.AI_VISIT_STT_ENGINE = <?php echo json_encode($sttEngine); ?>;
      window.AI_VISIT_LANGUAGE = <?php echo json_encode($dictationLanguage); ?>;
      window.AI_VISIT_LETTER_CONTEXT = <?php echo json_encode($letterContext, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    </script>
    <?php include(__DIR__ . "/form_body.html"); ?>
</form>
<script src="<?php echo $rootdir; ?>/forms/ai_visit_forms/dictation.js?v=46"></script>
</body>
</html>
    <?php
}
