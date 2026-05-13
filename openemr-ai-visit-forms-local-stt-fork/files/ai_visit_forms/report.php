<?php
/**
 * AI Visit Forms encounter report snippet.
 *
 * @package OpenEMR
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc");
require_once(__DIR__ . "/common.php");

function ai_visit_forms_report_fields($mode)
{
    if ($mode === 'soap') {
        return array(
            'soap-s' => 'S - Subjective',
            'soap-vitals' => 'Vitals',
            'soap-gen' => 'General',
            'soap-resp' => 'Respiratory',
            'soap-cvs' => 'CVS',
            'soap-abdo' => 'Abdomen',
            'soap-pelvis' => 'Pelvis',
            'soap-cns' => 'CNS',
            'soap-ix' => 'Investigations',
            'soap-adx' => 'Assessment / Diagnosis',
            'soap-ddx' => 'Differential Diagnosis',
            'soap-pix' => 'Planned Investigations',
            'soap-rx' => 'Treatment',
            'soap-edu' => 'Counselling',
            'soap-fu' => 'Follow-up',
            'soap-notes' => 'Notes',
        );
    }
    if ($mode === 'hpc') {
        return array(
            'hpc-pc' => 'Presenting Complaint',
            'hpc-hpi' => 'History of Presenting Complaint Details',
            'hpc-onset' => 'Onset / Duration',
            'hpc-char' => 'Character / Severity',
            'hpc-rad' => 'Radiation / Location',
            'hpc-mod' => 'Relieving / Aggravating',
            'hpc-assoc' => 'Associated Symptoms',
            'hpc-obhx' => 'OB/GYN History',
            'hpc-ros' => 'Review of Systems',
            'hpc-pmhx' => 'Past History',
            'hpc-meds' => 'Medications / Allergies',
            'hpc-fhx' => 'Family History',
            'hpc-ix-to-date' => 'Investigations to Date',
            'ex-vitals' => 'Vitals',
            'ex-gen' => 'General',
            'ex-resp' => 'Respiratory',
            'ex-cvs' => 'CVS',
            'ex-abdo' => 'Abdomen',
            'ex-pelvis' => 'Pelvis',
            'ex-cns' => 'CNS',
            'hpc-dx' => 'Assessment / Diagnosis',
            'hpc-ddx' => 'Differential Diagnosis',
            'hpc-ix' => 'Investigations Requested',
            'hpc-rx' => 'Treatment',
            'hpc-edu' => 'Counselling',
            'hpc-fu' => 'Follow-up',
            'hpc-notes' => 'Notes',
        );
    }
    return array(
        'pl-main' => 'Visit Note',
        'pl-notes' => 'Notes',
    );
}

function ai_visit_forms_report_allows_letter()
{
    if (!empty($GLOBALS['PDF_OUTPUT']) || !empty($_REQUEST['pdf']) || !empty($_REQUEST['printable'])) {
        return false;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return strpos($script, '/patient_file/encounter/forms.php') !== false;
}

function ai_visit_forms_report($pid, $encounter, $cols, $id)
{
    global $rootdir;

    $data = formFetch("form_ai_visit_forms", $id);
    $payload = json_decode($data['payload_json'] ?? '{}', true);
    if (!is_array($payload)) {
        $payload = array();
    }
    $controls = $payload['controls'] ?? array();
    if (!is_array($controls)) {
        $controls = array();
    }
    $mode = $data['dictation_mode'] ?? ($payload['currentMode'] ?? 'plain');
    $fields = ai_visit_forms_report_fields($mode);
    $allowLetter = ai_visit_forms_report_allows_letter();

    if ($allowLetter) {
        echo "<link rel='stylesheet' href='" . attr($rootdir) . "/forms/ai_visit_forms/style.css?v=46'>";
        echo "<style>.ai-visit-report-actions{margin:6px 0 12px;display:flex;gap:8px;flex-wrap:wrap}.ai-visit-report-actions .btn{font-size:12.5px;font-weight:700}.ai-visit-report .modal-overlay{text-align:left}.ai-visit-report .letter-preview{min-height:170px}</style>";
    }
    echo "<div class='ai-visit-report'>";
    echo "<h4>" . xlt("Advance Visit Form") . "</h4>";
    if ($allowLetter) {
        $uid = 'ai-visit-letter-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id);
        echo "<div class='ai-visit-report-actions'>";
        echo "<button type='button' class='btn btn-blue' data-ai-report-letter-action='open' data-ai-report-letter-uid='" . attr($uid) . "' onclick='aiVisitReportOpenLetter(" . json_encode($uid) . ")'>" . xlt("Generate Letter") . "</button>";
        echo "</div>";
    }

    foreach ($fields as $key => $label) {
        $value = $controls[$key] ?? '';
        if (trim((string) $value) === '') {
            continue;
        }
        echo "<p><strong>" . text($label) . ":</strong><br>";
        echo nl2br(text($value)) . "</p>";
    }

    if ($allowLetter) {
        $letterData = array(
            'mode' => $mode,
            'controls' => $controls,
            'context' => ai_visit_forms_letter_context($pid, $encounter, $rootdir),
        );
        ai_visit_forms_report_letter_modal($uid);
        echo "<script>window.AI_VISIT_REPORT_LETTERS=window.AI_VISIT_REPORT_LETTERS||{};window.AI_VISIT_REPORT_LETTERS[" . json_encode($uid) . "]=" . json_encode($letterData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ";</script>";
        echo "<script src='" . attr($rootdir) . "/forms/ai_visit_forms/report_letter.js?v=5'></script>";
    }
    echo "</div>";
}

function ai_visit_forms_report_letter_modal($uid)
{
    $aUid = attr($uid);
    $jUid = json_encode($uid);
    ?>
<div class="modal-overlay" id="<?php echo $aUid; ?>-modal" data-ai-report-letter-uid="<?php echo $aUid; ?>">
  <div class="modal-box">
    <div class="modal-header">
      <span><?php echo xlt("Generate Letter From Signed Note"); ?></span>
      <button type="button" class="modal-close" data-ai-report-letter-action="close" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportCloseLetter(<?php echo $jUid; ?>)">&#10005;</button>
    </div>
    <div class="modal-body">
      <div class="letter-config">
        <div class="letter-config-wide">
          <label><?php echo xlt("Sender Facility"); ?></label>
          <select id="<?php echo $aUid; ?>-facility" data-ai-report-letter-action="build" data-ai-report-letter-uid="<?php echo $aUid; ?>" onchange="aiVisitReportBuildLetter(<?php echo $jUid; ?>)">
            <option value=""><?php echo xlt("Use OpenEMR encounter/default facility..."); ?></option>
          </select>
        </div>
        <div class="letter-config-wide">
          <label><?php echo xlt("Address Book"); ?></label>
          <select id="<?php echo $aUid; ?>-ref-select" data-ai-report-letter-action="select-ref" data-ai-report-letter-uid="<?php echo $aUid; ?>" onchange="aiVisitReportSelectRef(<?php echo $jUid; ?>)">
            <option value=""><?php echo xlt("Select referring physician from OpenEMR address book..."); ?></option>
          </select>
        </div>
        <div>
          <label><?php echo xlt("Referring Physician"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-ref-name" placeholder="Dr. A. Smith">
        </div>
        <div>
          <label><?php echo xlt("Referring Practice / Hospital"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-ref-practice" placeholder="Nassau Medical Centre">
        </div>
        <div class="letter-config-wide">
          <label><?php echo xlt("Recipient Address"); ?></label>
          <textarea id="<?php echo $aUid; ?>-ref-address" rows="3" placeholder="Street&#10;City, State ZIP"></textarea>
        </div>
        <div>
          <label><?php echo xlt("Recipient Email"); ?></label>
          <input type="email" id="<?php echo $aUid; ?>-ref-email" placeholder="doctor@example.com">
        </div>
        <div>
          <label><?php echo xlt("Salutation"); ?></label>
          <select id="<?php echo $aUid; ?>-salutation">
            <option>Dear Dr.</option>
            <option>Dear Colleague,</option>
            <option>To Whom It May Concern,</option>
          </select>
        </div>
        <div>
          <label><?php echo xlt("Patient Name"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-patient">
        </div>
        <div>
          <label><?php echo xlt("Patient DOB"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-dob">
        </div>
        <div>
          <label><?php echo xlt("Letter Date"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-date">
        </div>
        <div>
          <label><?php echo xlt("Consulting Physician"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-consultant">
        </div>
        <div>
          <label><?php echo xlt("Specialty"); ?></label>
          <input type="text" id="<?php echo $aUid; ?>-specialty">
        </div>
        <div>
          <label><?php echo xlt("Letter Type"); ?></label>
          <select id="<?php echo $aUid; ?>-type" data-ai-report-letter-action="build" data-ai-report-letter-uid="<?php echo $aUid; ?>" onchange="aiVisitReportBuildLetter(<?php echo $jUid; ?>)">
            <option value="consult">Consultation / Referral Response</option>
            <option value="discharge">Discharge Summary</option>
            <option value="results">Results / Investigation Report</option>
            <option value="followup">Follow-up Letter</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
        <button type="button" class="btn btn-blue" data-ai-report-letter-action="build" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportBuildLetter(<?php echo $jUid; ?>)">Generate Draft</button>
        <span class="letter-help"><?php echo xlt("Uses the saved final note fields. The letter is not saved back to the signed form."); ?></span>
      </div>
      <div class="letter-preview-wrap">
        <div class="letter-preview-hd">
          <?php echo xlt("Letter Preview"); ?>
          <div class="letter-mini-actions">
            <button type="button" class="btn" data-ai-report-letter-action="copy" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportCopyLetter(<?php echo $jUid; ?>)">Copy</button>
            <button type="button" class="btn" data-ai-report-letter-action="print" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportPrintLetter(<?php echo $jUid; ?>)">Print / PDF</button>
          </div>
        </div>
        <div class="letter-paper" id="<?php echo $aUid; ?>-letter-paper"></div>
        <textarea class="letter-preview" id="<?php echo $aUid; ?>-letter-text" rows="12" spellcheck="true" data-ai-report-letter-action="render" data-ai-report-letter-uid="<?php echo $aUid; ?>" oninput="aiVisitReportRenderLetter(<?php echo $jUid; ?>)"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-blue letter-footer-btn" data-ai-report-letter-action="copy" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportCopyLetter(<?php echo $jUid; ?>)">Copy Letter</button>
      <button type="button" class="btn btn-green letter-footer-btn" data-ai-report-letter-action="print" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportPrintLetter(<?php echo $jUid; ?>)">Print / Export PDF</button>
      <button type="button" class="btn btn-red letter-footer-btn" data-ai-report-letter-action="close" data-ai-report-letter-uid="<?php echo $aUid; ?>" onclick="aiVisitReportCloseLetter(<?php echo $jUid; ?>)">Close</button>
      <span class="letter-copied" id="<?php echo $aUid; ?>-letter-copied"></span>
    </div>
  </div>
</div>
    <?php
}
