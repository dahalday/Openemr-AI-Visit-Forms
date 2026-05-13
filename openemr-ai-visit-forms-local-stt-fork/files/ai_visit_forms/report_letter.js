(function() {
  if (window.aiVisitReportLetterLoaded) {
    return;
  }
  window.aiVisitReportLetterLoaded = true;
  window.AI_VISIT_REPORT_LETTERS = window.AI_VISIT_REPORT_LETTERS || {};

  function item(uid) {
    return window.AI_VISIT_REPORT_LETTERS[uid] || { mode: 'plain', controls: {}, context: {} };
  }

  function el(uid, suffix) {
    return document.getElementById(uid + '-' + suffix);
  }

  function actionNode(target) {
    while (target && target !== document) {
      if (target.getAttribute && target.getAttribute('data-ai-report-letter-action')) {
        return target;
      }
      target = target.parentNode;
    }
    return null;
  }

  function uidFor(node) {
    if (!node || !node.getAttribute) {
      return '';
    }
    var uid = node.getAttribute('data-ai-report-letter-uid') || '';
    while (!uid && node && node !== document) {
      if (node.getAttribute) {
        uid = node.getAttribute('data-ai-report-letter-uid') || '';
      }
      node = node.parentNode;
    }
    return uid;
  }

  function stopOpenEmrWrapper(event) {
    event.preventDefault();
    if (event.stopImmediatePropagation) {
      event.stopImmediatePropagation();
    } else {
      event.stopPropagation();
    }
  }

  function value(uid, suffix) {
    var node = el(uid, suffix);
    return node ? String(node.value || '').trim() : '';
  }

  function setValue(uid, suffix, text) {
    var node = el(uid, suffix);
    if (node) {
      node.value = text || '';
    }
  }

  function setIfEmpty(uid, suffix, text) {
    var node = el(uid, suffix);
    if (node && !String(node.value || '').trim() && text) {
      node.value = text;
    }
  }

  function clean(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function uniqueNonEmpty(values) {
    var seen = {};
    return values.map(clean).filter(function(text) {
      if (!text || seen[text]) {
        return false;
      }
      seen[text] = true;
      return true;
    });
  }

  function firstNonEmpty(values) {
    for (var i = 0; i < values.length; i++) {
      if (values[i]) {
        return values[i];
      }
    }
    return '';
  }

  function control(data, id) {
    return String((data.controls || {})[id] || '').trim();
  }

  function context(data) {
    return data.context || {};
  }

  function senderFacilities(data) {
    var ctx = context(data);
    var facilities = ctx.facilities || [];
    if (!facilities.length && ctx.facility) {
      facilities = [ctx.facility];
    }
    return facilities;
  }

  function facilityLabel(facility) {
    facility = facility || {};
    var detail = [facility.street, facility.city, facility.country_code].filter(Boolean).join(', ');
    return (facility.name || 'Facility') + (detail ? ' - ' + detail : '');
  }

  function populateFacilitySelect(uid) {
    var data = item(uid);
    var select = el(uid, 'facility');
    if (!select || select.getAttribute('data-loaded') === '1') {
      return;
    }
    var ctx = context(data);
    var selectedId = ctx.facility && ctx.facility.id ? String(ctx.facility.id) : '';
    var selectedIndex = '';
    senderFacilities(data).forEach(function(facility, index) {
      var option = document.createElement('option');
      option.value = String(index);
      option.textContent = facilityLabel(facility);
      select.appendChild(option);
      if (selectedId && String(facility.id || '') === selectedId) {
        selectedIndex = String(index);
      }
    });
    if (selectedIndex !== '') {
      select.value = selectedIndex;
    }
    select.setAttribute('data-loaded', '1');
  }

  function selectedFacility(uid) {
    var data = item(uid);
    var select = el(uid, 'facility');
    var facilities = senderFacilities(data);
    if (!select || select.value === '') {
      return context(data).facility || facilities[0] || {};
    }
    return facilities[parseInt(select.value, 10)] || context(data).facility || {};
  }

  function referringProviders(data) {
    return context(data).referring_providers || [];
  }

  function populateReferringSelect(uid) {
    var data = item(uid);
    var select = el(uid, 'ref-select');
    if (!select || select.getAttribute('data-loaded') === '1') {
      return;
    }
    referringProviders(data).forEach(function(provider, index) {
      var option = document.createElement('option');
      var detail = [provider.practice, provider.specialty].filter(Boolean).join(' - ');
      option.value = String(index);
      option.textContent = provider.name + (detail ? ' (' + detail + ')' : '');
      select.appendChild(option);
    });
    select.setAttribute('data-loaded', '1');
  }

  function selectedReferringProvider(uid) {
    var data = item(uid);
    var select = el(uid, 'ref-select');
    if (!select || select.value === '') {
      return null;
    }
    return referringProviders(data)[parseInt(select.value, 10)] || null;
  }

  function fillDefaults(uid) {
    var data = item(uid);
    var ctx = context(data);
    var today = new Date();
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    setIfEmpty(uid, 'date', today.getDate() + ' ' + months[today.getMonth()] + ' ' + today.getFullYear());
    setIfEmpty(uid, 'patient', ctx.patient && ctx.patient.name ? ctx.patient.name : '');
    setIfEmpty(uid, 'dob', ctx.patient && ctx.patient.dob ? ctx.patient.dob : '');
    setIfEmpty(uid, 'consultant', ctx.provider && ctx.provider.name ? ctx.provider.name : '');
    setIfEmpty(uid, 'specialty', ctx.provider && ctx.provider.specialty ? ctx.provider.specialty : '');
  }

  function addressLines(facility) {
    facility = facility || {};
    var lines = [];
    if (facility.street) {
      lines.push(facility.street);
    }
    var cityLine = [facility.city, facility.state, facility.postal_code].filter(Boolean).join(', ');
    if (cityLine) {
      lines.push(cityLine);
    }
    if (facility.country_code) {
      lines.push(facility.country_code);
    }
    return lines.join('\n');
  }

  function senderLines(providerName, facility) {
    facility = facility || {};
    var lines = [];
    var address = addressLines(facility);
    if (providerName) {
      lines.push(providerName);
    }
    if (facility.name) {
      lines.push(facility.name);
    }
    if (address) {
      lines.push(address);
    }
    if (facility.phone) {
      lines.push('Telephone: ' + facility.phone);
    }
    if (facility.fax) {
      lines.push('Fax: ' + facility.fax);
    }
    return lines.join('\n');
  }

  function extractClinical(data) {
    var d = {};
    var plainMain = control(data, 'pl-main');
    var plainNotes = control(data, 'pl-notes');
    var structuredValues = [
      'hpc-pc', 'hpc-hpi', 'hpc-onset', 'hpc-char', 'hpc-rad', 'hpc-mod', 'hpc-assoc',
      'hpc-obhx', 'hpc-ros', 'hpc-pmhx', 'hpc-meds', 'hpc-fhx', 'hpc-ix-to-date', 'ex-vitals', 'ex-gen',
      'ex-resp', 'ex-cvs', 'ex-abdo', 'ex-pelvis', 'ex-cns', 'hpc-dx',
      'hpc-ddx', 'hpc-ix', 'hpc-rx', 'hpc-edu', 'hpc-fu', 'soap-s',
      'soap-vitals', 'soap-gen', 'soap-resp', 'soap-cvs', 'soap-abdo',
      'soap-pelvis', 'soap-cns', 'soap-ix', 'soap-adx', 'soap-ddx',
      'soap-pix', 'soap-rx', 'soap-edu', 'soap-fu'
    ].map(function(id) {
      return control(data, id);
    });
    var hasStructured = structuredValues.some(function(text) {
      return !!text;
    });

    if (!hasStructured) {
      d.pc = '';
      d.hx = plainMain;
      d.exam = '';
      d.ix = '';
      d.assess = plainNotes;
      d.plan = '';
      d.raw = uniqueNonEmpty([d.hx, d.assess]).join('\n\n');
      return d;
    }

    d.pc = control(data, 'hpc-pc');
    d.hx = uniqueNonEmpty([
      control(data, 'soap-s'),
      control(data, 'hpc-hpi') ? 'HPC details: ' + control(data, 'hpc-hpi') : '',
      control(data, 'hpc-onset') ? 'Onset/Duration: ' + control(data, 'hpc-onset') : '',
      control(data, 'hpc-char') ? 'Character/Severity: ' + control(data, 'hpc-char') : '',
      control(data, 'hpc-rad') ? 'Radiation/Location: ' + control(data, 'hpc-rad') : '',
      control(data, 'hpc-mod') ? 'Relieving/Aggravating: ' + control(data, 'hpc-mod') : '',
      control(data, 'hpc-assoc') ? 'Associated symptoms: ' + control(data, 'hpc-assoc') : '',
      control(data, 'hpc-obhx') ? 'OB/GYN history: ' + control(data, 'hpc-obhx') : '',
      control(data, 'hpc-ros') ? 'Review of systems: ' + control(data, 'hpc-ros') : '',
      control(data, 'hpc-pmhx') ? 'PMHx: ' + control(data, 'hpc-pmhx') : '',
      control(data, 'hpc-meds') ? 'Medications/Allergies: ' + control(data, 'hpc-meds') : '',
      control(data, 'hpc-fhx') ? 'Family history: ' + control(data, 'hpc-fhx') : '',
      control(data, 'hpc-ix-to-date') ? 'Investigations to date: ' + control(data, 'hpc-ix-to-date') : ''
    ]).join('\n') || plainMain;

    d.exam = uniqueNonEmpty([
      firstNonEmpty([control(data, 'ex-vitals'), control(data, 'soap-vitals')]) ? 'Vitals: ' + firstNonEmpty([control(data, 'ex-vitals'), control(data, 'soap-vitals')]) : '',
      firstNonEmpty([control(data, 'ex-gen'), control(data, 'soap-gen')]) ? 'General: ' + firstNonEmpty([control(data, 'ex-gen'), control(data, 'soap-gen')]) : '',
      firstNonEmpty([control(data, 'ex-resp'), control(data, 'soap-resp')]) ? 'Respiratory: ' + firstNonEmpty([control(data, 'ex-resp'), control(data, 'soap-resp')]) : '',
      firstNonEmpty([control(data, 'ex-cvs'), control(data, 'soap-cvs')]) ? 'CVS: ' + firstNonEmpty([control(data, 'ex-cvs'), control(data, 'soap-cvs')]) : '',
      firstNonEmpty([control(data, 'ex-abdo'), control(data, 'soap-abdo')]) ? 'Abdomen: ' + firstNonEmpty([control(data, 'ex-abdo'), control(data, 'soap-abdo')]) : '',
      firstNonEmpty([control(data, 'ex-pelvis'), control(data, 'soap-pelvis')]) ? 'Pelvis: ' + firstNonEmpty([control(data, 'ex-pelvis'), control(data, 'soap-pelvis')]) : '',
      firstNonEmpty([control(data, 'ex-cns'), control(data, 'soap-cns')]) ? 'CNS: ' + firstNonEmpty([control(data, 'ex-cns'), control(data, 'soap-cns')]) : ''
    ]).join('\n');

    d.ix = firstNonEmpty([control(data, 'hpc-ix-to-date'), control(data, 'hpc-ix'), control(data, 'soap-ix'), control(data, 'soap-pix')]);
    d.assess = uniqueNonEmpty([
      control(data, 'hpc-dx'),
      control(data, 'soap-adx'),
      control(data, 'hpc-ddx') ? 'DDx: ' + control(data, 'hpc-ddx') : '',
      control(data, 'soap-ddx') ? 'DDx: ' + control(data, 'soap-ddx') : '',
      plainNotes
    ]).join('\n');
    d.plan = uniqueNonEmpty([
      control(data, 'hpc-ix') ? 'Investigations requested: ' + control(data, 'hpc-ix') : '',
      control(data, 'soap-pix') ? 'Investigations: ' + control(data, 'soap-pix') : '',
      firstNonEmpty([control(data, 'hpc-rx'), control(data, 'soap-rx')]) ? 'Treatment: ' + firstNonEmpty([control(data, 'hpc-rx'), control(data, 'soap-rx')]) : '',
      firstNonEmpty([control(data, 'hpc-edu'), control(data, 'soap-edu')]) ? 'Counselling: ' + firstNonEmpty([control(data, 'hpc-edu'), control(data, 'soap-edu')]) : '',
      firstNonEmpty([control(data, 'hpc-fu'), control(data, 'soap-fu')]) ? 'Follow-up: ' + firstNonEmpty([control(data, 'hpc-fu'), control(data, 'soap-fu')]) : ''
    ]).join('\n');
    d.raw = uniqueNonEmpty([d.pc, d.hx, d.exam, d.ix, d.assess, d.plan, plainMain]).join('\n\n');
    return d;
  }

  function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, function(ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
    });
  }

  function logoHtml(data) {
    var ctx = context(data);
    if (!ctx.logo_url) {
      return '';
    }
    return '<img class="letter-paper-logo" src="' + escapeHtml(ctx.logo_url) + '" alt="Practice logo">';
  }

  function letterHtml(uid) {
    var data = item(uid);
    var text = value(uid, 'letter-text');
    var parts = String(text || '').split(/\n{2,}/);
    var fromBlock = parts.shift() || '';
    var rest = parts.join('\n\n');
    return '<div class="letter-paper-header"><div class="letter-paper-from">' +
      escapeHtml(fromBlock).replace(/\n/g, '<br>') +
      '</div>' + logoHtml(data) + '</div>' +
      '<div class="letter-paper-body">' + escapeHtml(rest).replace(/\n/g, '<br>') + '</div>';
  }

  window.aiVisitReportRenderLetter = function(uid) {
    var paper = el(uid, 'letter-paper');
    if (paper) {
      paper.innerHTML = letterHtml(uid);
    }
  };

  window.aiVisitReportBuildLetter = function(uid) {
    var data = item(uid);
    var ctx = context(data);
    fillDefaults(uid);
    var refName = value(uid, 'ref-name') || '[Referring Physician Name]';
    var refPractice = value(uid, 'ref-practice') || '[Practice / Hospital]';
    var refAddress = value(uid, 'ref-address');
    var refEmail = value(uid, 'ref-email');
    var salutation = value(uid, 'salutation') || 'Dear Dr.';
    var patient = value(uid, 'patient') || '[Patient Name]';
    var dob = value(uid, 'dob') || '[DOB]';
    var letterDate = value(uid, 'date') || '[Date]';
    var consultant = value(uid, 'consultant') || (ctx.provider && ctx.provider.name ? ctx.provider.name : '[OpenEMR Provider]');
    var specialty = value(uid, 'specialty') || (ctx.provider && ctx.provider.specialty ? ctx.provider.specialty : 'Clinical Service');
    var letterType = value(uid, 'type') || 'consult';
    var senderFacility = selectedFacility(uid);
    var selectedRef = selectedReferringProvider(uid);
    var clinical = extractClinical(data);

    var salutationName = selectedRef && selectedRef.salutation_name ? selectedRef.salutation_name : refName.replace(/^Dr\.?\s*/i, '');
    var sal = salutation === 'Dear Dr.' ? salutation + ' ' + salutationName + ',' : salutation;
    var recipientBlock = [refName, refPractice, refAddress, refEmail ? 'Email: ' + refEmail : ''].filter(Boolean).join('\n');
    var header = senderLines(consultant, senderFacility) + '\n\n' +
      letterDate + '\n\n' +
      recipientBlock + '\n\n' +
      'Re: ' + patient + '   DOB: ' + dob + '\n' +
      '-----------------------------------------\n\n' +
      sal + '\n\n';
    var body = '';

    if (letterType === 'consult') {
      body = 'Thank you for referring the above-named patient to our ' + specialty + ' service. I had the pleasure of reviewing ' +
        patient + ' on ' + letterDate + '. I am pleased to report my findings and management plan below.\n\n';
      if (clinical.pc) body += 'PRESENTING COMPLAINT\n' + clinical.pc + '\n\n';
      if (clinical.hx) body += 'HISTORY\n' + clinical.hx + '\n\n';
      if (clinical.exam) body += 'EXAMINATION\n' + clinical.exam + '\n\n';
      if (clinical.ix) body += 'INVESTIGATIONS\n' + clinical.ix + '\n\n';
      if (clinical.assess) body += 'ASSESSMENT\n' + clinical.assess + '\n\n';
      if (clinical.plan) body += 'PLAN\n' + clinical.plan + '\n\n';
      body += 'I will continue to follow this patient and will keep you informed of further developments. Please do not hesitate to contact me should you have any queries.\n\n';
    } else if (letterType === 'discharge') {
      body = 'I am writing to inform you that ' + patient + ' has been discharged from our care following assessment on ' + letterDate + '.\n\n';
      if (clinical.pc || clinical.hx) body += 'CLINICAL SUMMARY\n' + uniqueNonEmpty([clinical.pc, clinical.hx]).join('\n') + '\n\n';
      if (clinical.assess) body += 'DIAGNOSIS\n' + clinical.assess + '\n\n';
      if (clinical.exam) body += 'CLINICAL FINDINGS\n' + clinical.exam + '\n\n';
      if (clinical.ix) body += 'INVESTIGATIONS\n' + clinical.ix + '\n\n';
      if (clinical.plan) body += 'DISCHARGE PLAN / FOLLOW-UP\n' + clinical.plan + '\n\n';
      body += 'Please resume primary care of this patient. I would be grateful if you could ensure ongoing follow-up as outlined above.\n\n';
    } else if (letterType === 'results') {
      body = 'I am writing regarding the results of investigations arranged for ' + patient + '.\n\n';
      if (clinical.pc || clinical.hx) body += 'CLINICAL SUMMARY\n' + uniqueNonEmpty([clinical.pc, clinical.hx]).join('\n') + '\n\n';
      if (clinical.ix) body += 'RESULTS\n' + clinical.ix + '\n\n';
      if (clinical.assess) body += 'INTERPRETATION\n' + clinical.assess + '\n\n';
      if (clinical.plan) body += 'RECOMMENDED ACTION\n' + clinical.plan + '\n\n';
      body += 'I would be grateful for your continued management of this patient in accordance with the above recommendations.\n\n';
    } else {
      body = 'I am writing following the review of ' + patient + ' in our ' + specialty + ' clinic on ' + letterDate + '.\n\n';
      if (clinical.pc || clinical.hx) body += 'CLINICAL SUMMARY\n' + uniqueNonEmpty([clinical.pc, clinical.hx]).join('\n') + '\n\n';
      if (clinical.assess) body += 'CURRENT STATUS\n' + clinical.assess + '\n\n';
      if (clinical.exam) body += 'EXAMINATION TODAY\n' + clinical.exam + '\n\n';
      if (clinical.ix) body += 'INVESTIGATIONS PENDING / REVIEWED\n' + clinical.ix + '\n\n';
      if (clinical.plan) body += 'ONGOING PLAN\n' + clinical.plan + '\n\n';
      body += 'We will continue to see this patient in clinic. Thank you for your ongoing involvement in her care.\n\n';
    }

    setValue(uid, 'letter-text', header + body +
      'Yours sincerely,\n\n\n' +
      consultant + '\n' +
      specialty + '\n' +
      (senderFacility && senderFacility.name ? senderFacility.name + '\n\n' : '\n') +
      'Dictated and not read\n' +
      'Confidential - intended for named recipient only');
    window.aiVisitReportRenderLetter(uid);
  };

  window.aiVisitReportSelectRef = function(uid) {
    var provider = selectedReferringProvider(uid);
    if (!provider) {
      return;
    }
    setValue(uid, 'ref-name', provider.name || '');
    setValue(uid, 'ref-practice', provider.practice || '');
    setValue(uid, 'ref-address', provider.address || '');
    setValue(uid, 'ref-email', provider.email || '');
    window.aiVisitReportBuildLetter(uid);
  };

  window.aiVisitReportOpenLetter = function(uid) {
    populateFacilitySelect(uid);
    populateReferringSelect(uid);
    fillDefaults(uid);
    var modal = el(uid, 'modal');
    if (modal) {
      modal.style.display = 'flex';
      modal.classList.add('visible');
      modal.setAttribute('aria-hidden', 'false');
    }
    window.aiVisitReportBuildLetter(uid);
  };

  window.aiVisitReportCloseLetter = function(uid) {
    var modal = el(uid, 'modal');
    if (modal) {
      modal.classList.remove('visible');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
    }
  };

  function runAction(action, uid) {
    if (!uid) {
      return false;
    }
    if (action === 'open') {
      window.aiVisitReportOpenLetter(uid);
      return true;
    }
    if (action === 'build') {
      window.aiVisitReportBuildLetter(uid);
      return true;
    }
    if (action === 'select-ref') {
      window.aiVisitReportSelectRef(uid);
      return true;
    }
    if (action === 'render') {
      window.aiVisitReportRenderLetter(uid);
      return true;
    }
    if (action === 'copy') {
      window.aiVisitReportCopyLetter(uid);
      return true;
    }
    if (action === 'print') {
      window.aiVisitReportPrintLetter(uid);
      return true;
    }
    if (action === 'close') {
      window.aiVisitReportCloseLetter(uid);
      return true;
    }
    return false;
  }

  document.addEventListener('click', function(event) {
    var node = actionNode(event.target);
    if (node) {
      var tag = String(node.tagName || '').toUpperCase();
      var action = node.getAttribute('data-ai-report-letter-action');
      if (tag === 'BUTTON' && runAction(action, uidFor(node))) {
        stopOpenEmrWrapper(event);
        return;
      }
    }
    var target = event.target;
    if (!target || !target.classList || !target.classList.contains('modal-overlay')) {
      return;
    }
    if (!target.id || target.id.indexOf('ai-visit-letter-') !== 0) {
      return;
    }
    stopOpenEmrWrapper(event);
    window.aiVisitReportCloseLetter(target.id.replace(/-modal$/, ''));
  }, true);

  document.addEventListener('change', function(event) {
    var node = actionNode(event.target);
    if (!node) {
      return;
    }
    var action = node.getAttribute('data-ai-report-letter-action');
    if ((action === 'build' || action === 'select-ref') && runAction(action, uidFor(node))) {
      if (event.stopImmediatePropagation) {
        event.stopImmediatePropagation();
      } else {
        event.stopPropagation();
      }
    }
  }, true);

  document.addEventListener('input', function(event) {
    var node = actionNode(event.target);
    if (!node) {
      return;
    }
    if (node.getAttribute('data-ai-report-letter-action') === 'render') {
      runAction('render', uidFor(node));
    }
  }, true);

  document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') {
      return;
    }
    var modals = document.querySelectorAll('.modal-overlay.visible[id^="ai-visit-letter-"]');
    if (modals.length) {
      stopOpenEmrWrapper(event);
    }
    for (var i = 0; i < modals.length; i++) {
      window.aiVisitReportCloseLetter(modals[i].id.replace(/-modal$/, ''));
    }
  }, true);

  window.aiVisitReportCopyLetter = function(uid) {
    var textarea = el(uid, 'letter-text');
    if (!textarea) {
      return;
    }
    var note = el(uid, 'letter-copied');
    function copied() {
      if (note) {
        note.textContent = 'Copied to clipboard';
        setTimeout(function() { note.textContent = ''; }, 2500);
      }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(textarea.value).then(copied).catch(function() {
        textarea.select();
        document.execCommand('copy');
        copied();
      });
      return;
    }
    textarea.select();
    document.execCommand('copy');
    copied();
  };

  window.aiVisitReportPrintLetter = function(uid) {
    window.aiVisitReportRenderLetter(uid);
    var win = window.open('', '_blank');
    win.document.write(
      '<html><head><title>Referral Letter</title>' +
      '<style>body{font-family:Georgia,serif;font-size:13px;line-height:1.7;margin:40px 60px;color:#222;}' +
      '.letter-paper-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:24px;}' +
      '.letter-paper-from{white-space:normal;line-height:1.55;}' +
      '.letter-paper-logo{max-width:180px;max-height:82px;object-fit:contain;display:block;margin:0 0 0 24px;}' +
      '.letter-paper-body{white-space:normal;}' +
      '@media print{body{margin:20mm;}}</style></head>' +
      '<body>' + letterHtml(uid) +
      '<script>window.onload=function(){window.print();}<\/script></body></html>'
    );
    win.document.close();
  };
})();
