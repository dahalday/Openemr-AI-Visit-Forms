<?php
/**
 * AI Visit Forms save handler.
 *
 * @package OpenEMR
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc");
require_once("$srcdir/forms.inc");

if ($encounter == "") {
    $encounter = date("Ymd");
}

$values = array(
    'dictation_mode' => $_POST['dictation_mode'] ?? 'plain',
    'llm_provider' => $_POST['llm_provider'] ?? 'local',
    'payload_json' => $_POST['payload_json'] ?? '{}',
    'letter_text' => '',
);

if (($_GET["mode"] ?? '') == "new") {
    $newid = formSubmit("form_ai_visit_forms", $values, $_GET["id"] ?? null, $userauthorized);
    addForm($encounter, "Advance Visit Form", $newid, "ai_visit_forms", $pid, $userauthorized);
} elseif (($_GET["mode"] ?? '') == "update") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) {
        formHeader("Advance Visit Form Save Error");
        echo "<div class='alert alert-danger'>Missing Advance Visit Form record id for update. Please close this tab and reopen the encounter form.</div>";
        formFooter();
        exit;
    }
    formUpdate("form_ai_visit_forms", $values, $id, $userauthorized);
}

$_SESSION["encounter"] = $encounter;
formHeader("Redirecting....");
formJump();
formFooter();
