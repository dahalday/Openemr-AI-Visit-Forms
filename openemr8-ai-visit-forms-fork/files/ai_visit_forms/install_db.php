<?php
/**
 * Installer for AI Visit Forms.
 *
 * @package OpenEMR
 */

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$ignoreAuth = true;

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/registry.inc");

installSQL(__DIR__);

$existing = getRegistryEntryByDirectory('ai_visit_forms');
if (!$existing) {
    registerForm('ai_visit_forms', 1, 1, 1);
}

$entry = getRegistryEntryByDirectory('ai_visit_forms');
if ($entry && !empty($entry['id'])) {
    updateRegistered(
        $entry['id'],
        "name='Advance Visit Form', nickname='Advance Visit Form', state=1, sql_run=1, unpackaged=1, category='Clinical', priority=8, aco_spec='encounters|notes'"
    );
}

echo "Advance Visit Form installed and registered.\n";
