<?php
/**
 * AI Visit Forms existing encounter form.
 *
 * @package OpenEMR
 */

require_once("common.php");

$id = $_GET['id'] ?? null;
$record = $id ? formFetch("form_ai_visit_forms", $id) : array();
ai_visit_forms_render('update', $id, $record ?: array());
