var currentMode = 'plain';
var isRecording = false;
var recordingWanted = false;
var recognition = null;
var recognitionRestartTimer = null;
var activeDictationTargetId = '';
var lastAutosaveAt = 0;
var pendingAIFields = null;
var lastDictationText = '';
var activeInterimText = '';
var activeInterimTargetId = '';
var lastChromeFinalText = '';
var lastChromeFinalTargetId = '';
var lastChromeFinalAt = 0;
var mediaStream = null;
var mediaRecorder = null;
var whisperQueue = [];
var whisperBusy = false;
var whisperChunkCount = 0;
var whisperMimeType = '';
var sttFatalErrorActive = false;
var transcriptSegments = [];
var whisperSegmentTimer = null;
var whisperAudioParts = [];
var micLivePromotable = false;
var lastProcessedSegmentCount = 0;
var pendingAITranscriptSegmentCount = 0;
var dictationSessionOpen = false;
var currentDictationStartSegmentCount = 0;

var providers = {
  local:  { name: 'Local \u2014 Ollama',        cloud: false, hint: '\uD83D\uDDA5 No data leaves server' },
  gpt:    { name: 'GPT API \u2014 OpenAI',      cloud: true,  hint: '\u2601 PHI scrubbed \u00B7 OpenAI cloud' },
  claude: { name: 'Claude API \u2014 Anthropic', cloud: true,  hint: '\u2601 PHI scrubbed \u00B7 Anthropic cloud' },
  gemma:  { name: 'Google AI \u2014 Gemini/Gemma', cloud: true,  hint: '\u2601 PHI scrubbed \u00B7 Google AI cloud' }
};

function normalizeProviderValue(val) {
  val = (val || '').toLowerCase();
  if (val === 'openai') return 'gpt';
  return providers[val] ? val : 'local';
}

function setMode(m) {
  currentMode = m;
  ['plain','hpc','soap'].forEach(function(id) {
    document.getElementById('btn-' + id).classList.toggle('active', id === m);
    document.getElementById('panel-' + id).classList.toggle('visible', id === m);
  });
  aiVisitAutosaveDraft();
}

function setHpcTab(tab, btn) {
  ['hist','exam','imp','plan','notes'].forEach(function(t) {
    document.getElementById('hsub-' + t).classList.remove('visible');
    document.getElementById('htab-' + t).classList.remove('active');
  });
  document.getElementById('hsub-' + tab).classList.add('visible');
  btn.classList.add('active');
}

function setSoapTab(tab, btn) {
  ['s','o','a','p','n'].forEach(function(t) {
    document.getElementById('ssub-' + t).classList.remove('visible');
    document.getElementById('stab-' + t).classList.remove('active');
  });
  document.getElementById('ssub-' + tab).classList.add('visible');
  btn.classList.add('active');
}

function setProvider(val) {
  val = normalizeProviderValue(val);
  var providerSelect = document.querySelector('.prov-select');
  if (providerSelect && providerSelect.value !== val) providerSelect.value = val;
  var p = providers[val];
  if (!p) p = providers.local;
  var modelContext = window.AI_VISIT_MODEL_CONTEXT && window.AI_VISIT_MODEL_CONTEXT[val] ? window.AI_VISIT_MODEL_CONTEXT[val] : null;
  document.getElementById('prov-name').textContent = modelContext && modelContext.label ? modelContext.label : p.name;
  document.getElementById('prov-dot').className = 'prov-dot' + (p.cloud ? ' cloud' : '');
  document.getElementById('prov-hint').textContent = p.hint;
  document.getElementById('phi-warn').style.display = p.cloud ? 'block' : 'none';
  document.getElementById('phi-ok').style.display   = p.cloud ? 'block' : 'none';
  aiVisitAutosaveDraft();
}

function toggleMic() {
  if (recordingWanted) {
    stopPersistentDictation();
  } else {
    startPersistentDictation();
  }
}

function getSttEngine() {
  return (window.AI_VISIT_STT_ENGINE || 'chrome').toLowerCase();
}

function getSpeechRecognitionCtor() {
  return window.SpeechRecognition || window.webkitSpeechRecognition || null;
}

function isChunkedSttEngine() {
  var engine = getSttEngine();
  return engine === 'whisper_cpp' || engine === 'google_cloud' || engine === 'deepgram_nova3_medical' || engine.indexOf('faster_whisper') === 0;
}

function sttEngineLabel() {
  var engine = getSttEngine();
  if (engine === 'google_cloud') return 'Google Cloud Speech-to-Text';
  if (engine === 'deepgram_nova3_medical') return 'Deepgram Nova-3 Medical';
  if (engine === 'faster_whisper_cpu') return 'faster-whisper CPU';
  if (engine === 'faster_whisper_gpu') return 'faster-whisper GPU';
  if (engine === 'faster_whisper_max') return 'faster-whisper max accuracy';
  if (engine === 'faster_whisper_auto') return 'faster-whisper auto';
  return 'local Whisper';
}

function getDictationTarget() {
  var focused = document.activeElement;
  if (focused && focused.tagName === 'TEXTAREA' && focused.id && focused.id !== 'review-text' && focused.id !== 'letter-text') {
    activeDictationTargetId = focused.id;
    return focused;
  }

  var savedTarget = activeDictationTargetId ? document.getElementById(activeDictationTargetId) : null;
  if (savedTarget) return savedTarget;

  var fallbackId = currentMode === 'soap' ? 'soap-s' : (currentMode === 'hpc' ? 'hpc-pc' : 'pl-main');
  activeDictationTargetId = fallbackId;
  return document.getElementById(fallbackId);
}

function appendDictationText(text) {
  commitDictationText(text, getSttEngine() === 'chrome');
}

function cleanDictationText(text) {
  return (text || '').replace(/\s+/g, ' ').trim();
}

function appendToTextarea(target, text) {
  if (!target || !text) return;
  var spacer = target.value && !target.value.match(/\s$/) ? ' ' : '';
  target.value += spacer + text;
  target.classList.add('filled');
}

function dictationCompareToken(token) {
  return String(token || '').toLowerCase().replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '');
}

function dictationArticleToken(token) {
  return token === 'a' || token === 'an' || token === 'the';
}

function dictationTokensEquivalent(left, right) {
  if (left === right) return true;
  if (dictationArticleToken(left) && dictationArticleToken(right)) return true;
  return left.length >= 2 && right.length >= 2 && (left.indexOf(right) === 0 || right.indexOf(left) === 0);
}

function dictationCompareTokens(text) {
  return cleanDictationText(text).split(/\s+/).map(dictationCompareToken).filter(Boolean);
}

function commonDictationPrefixLength(left, right) {
  var count = Math.min(left.length, right.length);
  for (var i = 0; i < count; i++) {
    if (!dictationTokensEquivalent(left[i], right[i])) return i;
  }
  return count;
}

function containsTokenSequence(haystack, needle) {
  if (!needle.length || needle.length > haystack.length) return false;
  for (var i = 0; i <= haystack.length - needle.length; i++) {
    var matched = true;
    for (var j = 0; j < needle.length; j++) {
      if (!dictationTokensEquivalent(haystack[i + j], needle[j])) {
        matched = false;
        break;
      }
    }
    if (matched) return true;
  }
  return false;
}

function chromeTailReplaceCandidate(target, incomingText) {
  if (!target || !lastChromeFinalText || target.id !== lastChromeFinalTargetId) return null;
  if (Date.now() - lastChromeFinalAt > 30000) return null;

  var incoming = cleanDictationText(incomingText);
  var previous = cleanDictationText(lastChromeFinalText);
  if (!incoming || !previous || incoming === previous) return null;

  var oldTokens = dictationCompareTokens(previous);
  var newTokens = dictationCompareTokens(incoming);
  if (!oldTokens.length || !newTokens.length) return null;

  var common = commonDictationPrefixLength(oldTokens, newTokens);
  var replace = common >= oldTokens.length;
  if (!replace && oldTokens.length >= 5) {
    replace = common >= Math.max(5, oldTokens.length - 1) && newTokens.length >= oldTokens.length - 1;
  }
  if (!replace) return null;

  var value = target.value || '';
  var index = value.lastIndexOf(previous);
  if (index < 0) return null;
  if (value.slice(index + previous.length).trim()) return null;
  return {
    previous: previous,
    incoming: incoming,
    before: value.slice(0, index)
  };
}

function replaceLastTranscriptSegment(previous, incoming) {
  if (!transcriptSegments.length) return;
  var last = transcriptSegments[transcriptSegments.length - 1];
  if (last && last.text === previous) {
    last.text = incoming;
    last.at = Date.now();
    syncFullTranscriptField();
  }
}

function replaceChromeFinalTail(target, incomingText) {
  var candidate = chromeTailReplaceCandidate(target, incomingText);
  if (!candidate) return false;
  var spacer = candidate.before && !candidate.before.match(/\s$/) ? ' ' : '';
  target.value = candidate.before + spacer + candidate.incoming;
  target.classList.add('filled');
  replaceLastTranscriptSegment(candidate.previous, candidate.incoming);
  lastChromeFinalText = candidate.incoming;
  lastChromeFinalAt = Date.now();
  lastDictationText = candidate.incoming;
  return true;
}

function recordChromeFinalText(target, text) {
  if (!target || !text || getSttEngine() !== 'chrome') return;
  lastChromeFinalText = cleanDictationText(text);
  lastChromeFinalTargetId = target.id;
  lastChromeFinalAt = Date.now();
}

function novelDictationText(existingText, incomingText) {
  var clean = cleanDictationText(incomingText);
  if (!clean) return '';

  var incomingWords = clean.split(/\s+/);
  var incomingTokens = incomingWords.map(dictationCompareToken).filter(Boolean);
  if (!incomingTokens.length) return '';

  var existingTokens = dictationCompareTokens(existingText);
  if (!existingTokens.length) return clean;

  if (containsTokenSequence(existingTokens.slice(-incomingTokens.length), incomingTokens)) {
    return '';
  }
  if (incomingTokens.length >= 4 && containsTokenSequence(existingTokens, incomingTokens)) {
    return '';
  }

  var maxOverlap = Math.min(existingTokens.length, incomingTokens.length, 120);
  for (var overlap = maxOverlap; overlap > 0; overlap--) {
    var ok = true;
    for (var i = 0; i < overlap; i++) {
      if (!dictationTokensEquivalent(existingTokens[existingTokens.length - overlap + i], incomingTokens[i])) {
        ok = false;
        break;
      }
    }
    if (ok) {
      return incomingWords.slice(overlap).join(' ');
    }
  }

  return clean;
}

function appendNovelToTextarea(target, text) {
  if (!target || !text) return '';
  var novel = novelDictationText(target.value, text);
  if (!novel) return '';
  appendToTextarea(target, novel);
  return novel;
}

function addTranscriptSegment(text, source, index) {
  var clean = cleanDictationText(text);
  if (!clean) return;
  var last = transcriptSegments.length ? transcriptSegments[transcriptSegments.length - 1].text : '';
  if (last === clean) return;
  transcriptSegments.push({
    text: clean,
    source: source || getSttEngine(),
    index: index || transcriptSegments.length + 1,
    at: Date.now()
  });
  syncFullTranscriptField();
}

function getFullTranscriptText() {
  return transcriptSegments.map(function(segment) {
    return segment.text;
  }).filter(Boolean).join('\n\n').trim();
}

function getUnprocessedTranscriptText() {
  clampProcessedSegmentCount();
  return transcriptSegments.slice(lastProcessedSegmentCount).map(function(segment) {
    return segment.text;
  }).filter(Boolean).join('\n\n').trim();
}

function getCurrentDictationTranscriptText() {
  clampProcessedSegmentCount();
  var start = dictationSessionOpen ? currentDictationStartSegmentCount : lastProcessedSegmentCount;
  if (start < lastProcessedSegmentCount) start = lastProcessedSegmentCount;
  if (start < 0) start = 0;
  if (start > transcriptSegments.length) start = transcriptSegments.length;
  return transcriptSegments.slice(start).map(function(segment) {
    return segment.text;
  }).filter(Boolean).join('\n\n').trim();
}

function beginDictationSession() {
  if (dictationSessionOpen) return;
  clampProcessedSegmentCount();
  currentDictationStartSegmentCount = transcriptSegments.length;
  if (lastProcessedSegmentCount < currentDictationStartSegmentCount) {
    markTranscriptProcessed(currentDictationStartSegmentCount);
  }
  dictationSessionOpen = true;
}

function closeDictationSessionAsProcessed(count) {
  markTranscriptProcessed(typeof count === 'number' ? count : transcriptSegments.length);
  currentDictationStartSegmentCount = lastProcessedSegmentCount;
  dictationSessionOpen = false;
}

function clampProcessedSegmentCount() {
  if (lastProcessedSegmentCount < 0) lastProcessedSegmentCount = 0;
  if (lastProcessedSegmentCount > transcriptSegments.length) {
    lastProcessedSegmentCount = transcriptSegments.length;
  }
  if (currentDictationStartSegmentCount < 0) currentDictationStartSegmentCount = 0;
  if (currentDictationStartSegmentCount > transcriptSegments.length) {
    currentDictationStartSegmentCount = transcriptSegments.length;
  }
}

function markTranscriptProcessed(count) {
  var processedCount = typeof count === 'number' ? count : transcriptSegments.length;
  lastProcessedSegmentCount = Math.max(0, Math.min(processedCount, transcriptSegments.length));
  syncFullTranscriptField();
}

function syncFullTranscriptField() {
  var el = document.getElementById('dictation-full-transcript');
  if (el) {
    el.value = getFullTranscriptText();
  }
}

function setMicLiveText(text, promotable) {
  var live = document.getElementById('mic-live');
  if (live) {
    live.textContent = text;
  }
  micLivePromotable = !!promotable;
}

function transcriptWordCount() {
  var text = getFullTranscriptText();
  if (!text) return 0;
  return text.split(/\s+/).filter(Boolean).length;
}

function whisperSavedChunkCount() {
  return transcriptSegments.filter(function(segment) {
    return segment.source === 'whisper';
  }).length;
}

function whisperProgressText(prefix) {
  return prefix + ' Saved chunks: ' + whisperSavedChunkCount() + '. Words saved: ' + transcriptWordCount() + '.';
}

function rebuildSegmentsFromFields() {
  if (transcriptSegments.length) return;
  var stored = getField('dictation-full-transcript');
  if (stored) {
    transcriptSegments = stored.split(/\n{2,}/).map(function(text, i) {
      return { text: cleanDictationText(text), source: 'restored', index: i + 1, at: Date.now() };
    }).filter(function(segment) { return segment.text; });
    syncFullTranscriptField();
    return;
  }
  var target = getDictationTarget();
  if (target && target.value.trim()) {
    addTranscriptSegment(target.value.trim(), 'field', 1);
  }
}

function commitPendingInterim() {
  if (!activeInterimText) return;
  if (getSttEngine() === 'chrome') {
    activeInterimText = '';
    activeInterimTargetId = '';
    return;
  }
  var target = activeInterimTargetId ? document.getElementById(activeInterimTargetId) : getDictationTarget();
  if (!target) return;
  var base = target.getAttribute('data-ai-visit-base-text');
  if (base !== null) {
    target.value = base;
    target.removeAttribute('data-ai-visit-base-text');
  }
  if (getSttEngine() === 'chrome' && replaceChromeFinalTail(target, activeInterimText)) {
    activeInterimText = '';
    activeInterimTargetId = '';
    aiVisitAutosaveDraft();
    return;
  }
  var appended = appendNovelToTextarea(target, activeInterimText);
  if (appended) {
    addTranscriptSegment(appended, 'interim', transcriptSegments.length + 1);
    lastDictationText = appended;
    recordChromeFinalText(target, appended);
  }
  activeInterimText = '';
  activeInterimTargetId = '';
  aiVisitAutosaveDraft();
}

function commitDictationText(text, allowChromeReplacement) {
  var target = getDictationTarget();
  if (!target || !text) return;

  var clean = cleanDictationText(text);
  if (!clean) return;

  var base = target.getAttribute('data-ai-visit-base-text');
  if (base !== null) {
    target.value = base;
    target.removeAttribute('data-ai-visit-base-text');
  }
  activeInterimText = '';
  activeInterimTargetId = '';
  if (allowChromeReplacement && replaceChromeFinalTail(target, clean)) {
    setMicLiveText(clean, false);
    aiVisitAutosaveDraft();
    return;
  }
  var appended = appendNovelToTextarea(target, clean);
  if (appended) {
    addTranscriptSegment(appended, getSttEngine(), transcriptSegments.length + 1);
    lastDictationText = appended;
    recordChromeFinalText(target, appended);
  }
  setMicLiveText(clean, false);
  aiVisitAutosaveDraft();
}

function previewDictationText(text) {
  var target = getDictationTarget();
  var clean = cleanDictationText(text);
  if (!target || !clean) return;
  if (getSttEngine() === 'chrome') {
    activeInterimText = clean;
    activeInterimTargetId = target.id;
    lastDictationText = clean;
    setMicLiveText(clean, true);
    return;
  }
  if (target.getAttribute('data-ai-visit-base-text') === null) {
    target.setAttribute('data-ai-visit-base-text', target.value);
  }
  var base = target.getAttribute('data-ai-visit-base-text') || '';
  var chromeCandidate = getSttEngine() === 'chrome' ? chromeTailReplaceCandidate(target, clean) : null;
  if (chromeCandidate) {
    var chromeSpacer = chromeCandidate.before && !chromeCandidate.before.match(/\s$/) ? ' ' : '';
    target.value = chromeCandidate.before + chromeSpacer + clean;
    target.classList.add('filled');
    activeInterimText = clean;
    activeInterimTargetId = target.id;
    lastDictationText = clean;
    setMicLiveText(clean, true);
    aiVisitAutosaveDraft();
    return;
  }
  var novel = novelDictationText(base, clean);
  if (!novel) {
    activeInterimText = '';
    activeInterimTargetId = '';
    setMicLiveText(clean, true);
    return;
  }
  var spacer = base && !base.match(/\s$/) ? ' ' : '';
  target.value = base + spacer + novel;
  target.classList.add('filled');
  activeInterimText = novel;
  activeInterimTargetId = target.id;
  lastDictationText = novel;
  setMicLiveText(clean, true);
  aiVisitAutosaveDraft();
}

function setRecordingUi(on, message) {
  isRecording = on;
  document.getElementById('mic-btn').classList.toggle('recording', isRecording);
  var st = document.getElementById('mic-status');
  if (on) {
    st.className = 'mic-status recording';
    st.textContent = message || '\u25CF Recording \u2014 speak now';
  } else {
    st.className = 'mic-status';
    st.textContent = message || 'Recording stopped \u2014 click Format with AI to process';
  }
}

function startPersistentDictation() {
  var wasRecordingWanted = recordingWanted;
  recordingWanted = true;
  sttFatalErrorActive = false;
  if (!wasRecordingWanted) {
    beginDictationSession();
  }
  activeDictationTargetId = getDictationTarget() ? getDictationTarget().id : activeDictationTargetId;
  aiVisitAutosaveDraft();

  if (isChunkedSttEngine()) {
    startWhisperDictation();
    return;
  }

  var RecognitionCtor = getSpeechRecognitionCtor();
  if (!RecognitionCtor) {
    setRecordingUi(true, '\u25CF Dictation armed \u2014 Chrome speech recognition unavailable');
    setMicLiveText('Your note is still autosaved; use Chrome Web Speech or local Whisper when configured.');
    return;
  }

  if (!recognition) {
    recognition = new RecognitionCtor();
    recognition.continuous = true;
    recognition.interimResults = false;
    recognition.lang = window.AI_VISIT_LANGUAGE || 'en-US';

    recognition.onresult = function(event) {
      var finalParts = [];
      var interim = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          finalParts.push(transcript);
        } else {
          interim += transcript;
        }
      }
      if (finalParts.length) {
        appendDictationText(finalParts.join(' '));
      }
      if (interim.trim()) {
        previewDictationText(interim);
      }
    };

    recognition.onerror = function(event) {
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        recordingWanted = false;
        setRecordingUi(false, 'Microphone permission blocked \u2014 allow mic access and try again');
        aiVisitAutosaveDraft();
        return;
      }
      if (recordingWanted) {
        setRecordingUi(false, 'Dictation paused \u2014 will resume when Chrome allows it');
      }
    };

    recognition.onend = function() {
      if (!recordingWanted) {
        commitPendingInterim();
        setRecordingUi(false, 'Recording stopped \u2014 click Format with AI to process');
        return;
      }
      if (document.hidden) {
        commitPendingInterim();
        setRecordingUi(false, 'Dictation paused while tab is hidden \u2014 will resume on return');
        aiVisitAutosaveDraft();
        return;
      }
      scheduleRecognitionRestart();
    };
  }

  try {
    recognition.start();
    setRecordingUi(true, '\u25CF Recording \u2014 autosaving, safe across tab switches');
  } catch (e) {
    scheduleRecognitionRestart();
  }
}

function bestWhisperMimeType() {
  var options = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg'];
  if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
  for (var i = 0; i < options.length; i++) {
    if (MediaRecorder.isTypeSupported(options[i])) return options[i];
  }
  return '';
}

function startWhisperDictation() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
    setRecordingUi(false, sttEngineLabel() + ' recording unavailable in this browser - using Chrome speech instead');
    window.AI_VISIT_STT_ENGINE = 'chrome';
    startPersistentDictation();
    return;
  }

  if (mediaRecorder && mediaRecorder.state === 'recording') {
    setRecordingUi(true, 'Recording with ' + sttEngineLabel() + ' - chunk ' + (whisperChunkCount + 1));
    setMicLiveText(whisperProgressText('Listening with ' + sttEngineLabel() + '...'));
    return;
  }

  navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
    mediaStream = stream;
    whisperMimeType = bestWhisperMimeType();
    startWhisperSegment();
  }).catch(function(error) {
    recordingWanted = false;
    setRecordingUi(false, 'Microphone unavailable for ' + sttEngineLabel() + ': ' + error.message);
    setMicLiveText('Microphone unavailable for ' + sttEngineLabel() + '. Transcript saved so far: ' + transcriptWordCount() + ' words.');
    aiVisitAutosaveDraft();
  });
}

function startWhisperSegment() {
  if (!recordingWanted || !mediaStream) return;
  whisperAudioParts = [];
  var options = whisperMimeType ? { mimeType: whisperMimeType } : {};

  try {
    mediaRecorder = new MediaRecorder(mediaStream, options);
  } catch (e) {
    recordingWanted = false;
    setRecordingUi(false, 'Could not start ' + sttEngineLabel() + ' recorder: ' + e.message);
    setMicLiveText('Could not start ' + sttEngineLabel() + ' recording. Transcript saved so far: ' + transcriptWordCount() + ' words.');
    stopMediaTracks();
    return;
  }

  mediaRecorder.ondataavailable = function(event) {
    if (event.data && event.data.size > 0) {
      whisperAudioParts.push(event.data);
    }
  };

  mediaRecorder.onerror = function() {
    setRecordingUi(false, sttEngineLabel() + ' recorder error - dictation saved so far');
    setMicLiveText(sttEngineLabel() + ' recorder error. Transcript saved so far: ' + transcriptWordCount() + ' words.');
    aiVisitAutosaveDraft();
  };

  mediaRecorder.onstop = function() {
    clearTimeout(whisperSegmentTimer);
    whisperSegmentTimer = null;
    if (sttFatalErrorActive) {
      whisperAudioParts = [];
      whisperQueue = [];
      stopMediaTracks();
      return;
    }

    var totalSize = whisperAudioParts.reduce(function(sum, part) {
      return sum + part.size;
    }, 0);
    if (totalSize > 1000) {
      whisperQueue.push({
        blob: new Blob(whisperAudioParts, { type: whisperMimeType || 'audio/webm' }),
        index: ++whisperChunkCount,
        mimeType: whisperMimeType || 'audio/webm',
        engine: getSttEngine()
      });
      setRecordingUi(recordingWanted, 'Recording with ' + sttEngineLabel() + ' - transcribing chunk ' + whisperChunkCount);
      setMicLiveText(whisperProgressText('Transcribing chunk ' + whisperChunkCount + '...'));
      processWhisperQueue();
    }

    whisperAudioParts = [];
    if (recordingWanted) {
      setTimeout(startWhisperSegment, 250);
    } else {
      stopMediaTracks();
      setRecordingUi(false, 'Recording stopped - final ' + sttEngineLabel() + ' chunks are being saved');
      setMicLiveText(whisperProgressText('Recording stopped. Saving final chunks.'));
    }
  };

  mediaRecorder.start();
  whisperSegmentTimer = setTimeout(function() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      try { mediaRecorder.stop(); } catch (e) {}
    }
  }, 10000);
  setRecordingUi(true, 'Recording with ' + sttEngineLabel() + ' - chunk ' + (whisperChunkCount + 1) + ' recording');
  setMicLiveText(whisperProgressText('Listening... chunk ' + (whisperChunkCount + 1) + ' is recording.'));
}

function stopWhisperDictation() {
  clearTimeout(whisperSegmentTimer);
  whisperSegmentTimer = null;
  if (mediaRecorder && mediaRecorder.state === 'recording') {
    try { mediaRecorder.stop(); } catch (e) {}
  } else {
    stopMediaTracks();
  }
}

function stopMediaTracks() {
  if (mediaStream) {
    mediaStream.getTracks().forEach(function(track) {
      try { track.stop(); } catch (e) {}
    });
  }
  mediaStream = null;
}

function isFatalSttError(error) {
  if (error && error.fatal) return true;
  var message = String(error && error.message ? error.message : error || '');
  return /no Google API key|no Deepgram API key|HTTP 401|HTTP 403|PERMISSION_DENIED|API has not been used|disabled|API key not valid|billing|Deepgram returned HTTP 401|Deepgram returned HTTP 403/i.test(message);
}

function stopChunkedSttAfterFatalError(message) {
  sttFatalErrorActive = true;
  recordingWanted = false;
  whisperQueue = [];
  clearTimeout(whisperSegmentTimer);
  whisperSegmentTimer = null;
  if (mediaRecorder && mediaRecorder.state === 'recording') {
    try { mediaRecorder.stop(); } catch (e) { stopMediaTracks(); }
  } else {
    stopMediaTracks();
  }
  var st = document.getElementById('mic-status');
  if (st) {
    st.className = 'mic-status';
    st.textContent = sttEngineLabel() + ' configuration error - recording stopped: ' + message;
  }
  setMicLiveText(sttEngineLabel() + ' configuration needs attention. Check the API key and service settings, then refresh and restart dictation.');
  aiVisitAutosaveDraft();
}

function processWhisperQueue() {
  if (whisperBusy || !whisperQueue.length) return;
  whisperBusy = true;
  var item = whisperQueue.shift();
  setMicLiveText(whisperProgressText('Transcribing chunk ' + item.index + '...'));
  var form = new FormData();
  var ext = item.mimeType.indexOf('ogg') !== -1 ? 'ogg' : 'webm';
  form.append('audio', item.blob, 'dictation-' + item.index + '.' + ext);
  form.append('engine', item.engine || getSttEngine());

  fetch(window.AI_VISIT_TRANSCRIBE_URL || 'transcribe.php', {
    method: 'POST',
    credentials: 'same-origin',
    body: form
  })
    .then(function(response) {
      return response.text().then(function(text) {
        var data = {};
        try {
          data = JSON.parse(text);
        } catch (e) {
          throw new Error('Transcription endpoint returned non-JSON: ' + text.replace(/\s+/g, ' ').slice(0, 160));
        }
        if (!response.ok) {
          var detail = data.body || data.detail || '';
          var err = new Error((data.error || 'Speech transcription failed.') + (detail ? ': ' + String(detail).slice(0, 120) : ''));
          err.fatal = !!data.fatal;
          throw err;
        }
        return data;
      });
    })
    .then(function(data) {
      var text = cleanDictationText(data.text || '');
      if (text) {
        var target = getDictationTarget();
        appendToTextarea(target, text);
        addTranscriptSegment(text, item.engine || getSttEngine(), item.index);
        lastDictationText = text;
        syncFullTranscriptField();
        aiVisitAutosaveDraft();
        setRecordingUi(recordingWanted, sttEngineLabel() + ' saved chunk ' + item.index + ' - transcript autosaved');
        setMicLiveText(text);
      } else {
        setRecordingUi(recordingWanted, sttEngineLabel() + ' chunk ' + item.index + ' had no speech');
        setMicLiveText(whisperProgressText('Chunk ' + item.index + ' had no speech.'));
      }
    })
    .catch(function(error) {
      var st = document.getElementById('mic-status');
      if (isFatalSttError(error)) {
        stopChunkedSttAfterFatalError(error.message);
        return;
      }
      st.className = 'mic-status';
      st.textContent = sttEngineLabel() + ' chunk ' + item.index + ' failed - recording continues: ' + error.message;
      setMicLiveText(whisperProgressText('Chunk ' + item.index + ' failed, but recording continues.'));
    })
    .then(function() {
      whisperBusy = false;
      aiVisitAutosaveDraft();
      processWhisperQueue();
    });
}

function scheduleRecognitionRestart() {
  clearTimeout(recognitionRestartTimer);
  recognitionRestartTimer = setTimeout(function() {
    if (recordingWanted && !document.hidden) {
      try {
        recognition.start();
        setRecordingUi(true, '\u25CF Recording resumed \u2014 autosaving');
      } catch (e) {
        setRecordingUi(false, 'Dictation paused \u2014 Chrome is not ready yet');
      }
    }
  }, 700);
}

function stopPersistentDictation() {
  recordingWanted = false;
  clearTimeout(recognitionRestartTimer);
  commitPendingInterim();
  if (isChunkedSttEngine()) {
    stopWhisperDictation();
  }
  if (recognition) {
    try { recognition.stop(); } catch (e) {}
  }
  setRecordingUi(false, 'Recording stopped \u2014 click Format with AI to process');
  aiVisitAutosaveDraft();
}

function runAI() {
  var rt = document.getElementById('review-text');
  commitPendingInterim();
  promoteMicLiveToField();
  rebuildSegmentsFromFields();
  var noteText = collectCurrentNoteText();
  if (!noteText) {
    var st = document.getElementById('mic-status');
    st.className = 'mic-status';
    st.textContent = 'Add or dictate notes first, then click Format with AI';
    return;
  }

  pendingAIFields = null;
  pendingAITranscriptSegmentCount = transcriptSegments.length;
  rt.value = 'Formatting with ' + getCurrentProviderLabel() + '. Please wait...';
  var rb = document.getElementById('review-box');
  rb.classList.add('visible');
  rb.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  setAIButtonsDisabled(true);

  fetch(window.AI_VISIT_FORMAT_URL || 'format.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({
      mode: currentMode,
      provider: getCurrentProvider(),
      transcript: noteText,
      existing_fields: aiVisitCollectPayload().controls
    })
  })
    .then(function(response) {
      return response.text().then(function(text) {
        var data = {};
        try {
          data = JSON.parse(text);
        } catch (e) {
          throw new Error('AI endpoint returned non-JSON response: ' + text.replace(/\s+/g, ' ').slice(0, 220));
        }
        if (!response.ok) {
          throw new Error(data.error || 'AI formatting failed.');
        }
        return data;
      });
    })
    .then(function(data) {
      pendingAIFields = data.fields || {};
      rt.value = renderAIReview(data);
      var st = document.getElementById('mic-status');
      st.className = 'mic-status';
      st.textContent = 'AI formatted output ready - review before accepting';
      aiVisitAutosaveDraft();
    })
    .catch(function(error) {
      rt.value = getCurrentProviderLabel() + ' formatting failed: ' + error.message + '\n\nFallback draft from current text:\n\n' + buildFormattedReview(noteText);
      pendingAIFields = null;
    })
    .then(function() {
      setAIButtonsDisabled(false);
    });
}

function acceptAI() {
  var reviewedText = document.getElementById('review-text').value;
  if (pendingAIFields) {
    applyAIFields(pendingAIFields);
  } else {
    if (currentMode === 'plain') {
      fillField('pl-main', reviewedText);
    } else if (currentMode === 'soap') {
      fillField(getActiveSoapFieldId(), reviewedText);
    } else if (currentMode === 'hpc') {
      fillField(getActiveHpcFieldId(), reviewedText);
    }
  }
  closeReview();
  var st = document.getElementById('mic-status');
  st.className = 'mic-status';
  st.textContent = '\u2714 AI fields accepted \u2014 review tabs and save';
  pendingAIFields = null;
  closeDictationSessionAsProcessed(pendingAITranscriptSegmentCount || transcriptSegments.length);
  pendingAITranscriptSegmentCount = 0;
  aiVisitAutosaveDraft();
}

function getCurrentProvider() {
  var providerSelect = document.querySelector('.prov-select');
  return normalizeProviderValue(providerSelect ? providerSelect.value : 'local');
}

function getCurrentProviderLabel() {
  var key = getCurrentProvider();
  return providers[key] ? providers[key].name : providers.local.name;
}

function setAIButtonsDisabled(disabled) {
  document.querySelectorAll('.btn-format-action, .review-actions .btn').forEach(function(btn) {
    btn.disabled = disabled;
  });
}

function renderAIReview(data) {
  var lines = [];
  var fields = data.fields || {};
  Object.keys(fields).forEach(function(key) {
    if (!fields[key]) return;
    lines.push(labelForAIField(key).toUpperCase());
    lines.push(fields[key]);
    lines.push('');
  });
  if (data.warnings && data.warnings.length) {
    lines.push('WARNINGS');
    data.warnings.forEach(function(warning) { lines.push('- ' + warning); });
    lines.push('');
  }
  lines.push('Confidence: ' + (data.confidence || 'medium'));
  return lines.join('\n').trim();
}

function applyAIFields(fields) {
  Object.keys(fields).forEach(function(key) {
    var value = fields[key];
    if (!value) return;
    var id = aiFieldToElementId(key);
    fillField(id, value);
  });
}

function aiFieldToElementId(key) {
  return key.replace(/_/g, '-');
}

function labelForAIField(key) {
  var labels = {
    hpc_pc: 'Presenting Complaint',
    hpc_hpi: 'History of Presenting Complaint Details',
    hpc_ros: 'Review of Systems',
    hpc_fhx: 'Family History',
    hpc_ix_to_date: 'Investigations to Date',
    hpc_ix: 'Investigations Requested'
  };
  if (labels[key]) return labels[key];
  return key.replace(/_/g, ' ');
}

function collectCurrentNoteText() {
  commitPendingInterim();
  promoteMicLiveToField();
  rebuildSegmentsFromFields();
  var currentTranscript = getCurrentDictationTranscriptText();
  if (currentTranscript) return currentTranscript;
  if (dictationSessionOpen) return '';
  var newTranscript = getUnprocessedTranscriptText();
  if (newTranscript) return newTranscript;
  var active = document.activeElement;
  if (active && active.tagName === 'TEXTAREA' && active.id && active.id !== 'review-text' && active.id !== 'letter-text' && active.value.trim()) {
    return active.value.trim();
  }

  var ids = currentMode === 'plain'
    ? ['pl-main', 'pl-notes']
    : (currentMode === 'soap'
      ? ['soap-s', 'soap-vitals', 'soap-gen', 'soap-resp', 'soap-cvs', 'soap-abdo', 'soap-pelvis', 'soap-cns', 'soap-ix', 'soap-adx', 'soap-ddx', 'soap-pix', 'soap-rx', 'soap-edu', 'soap-fu', 'soap-notes']
      : ['hpc-pc', 'hpc-hpi', 'hpc-onset', 'hpc-char', 'hpc-rad', 'hpc-mod', 'hpc-assoc', 'hpc-obhx', 'hpc-ros', 'hpc-pmhx', 'hpc-meds', 'hpc-fhx', 'hpc-ix-to-date', 'ex-vitals', 'ex-gen', 'ex-resp', 'ex-cvs', 'ex-abdo', 'ex-pelvis', 'ex-cns', 'hpc-dx', 'hpc-ddx', 'hpc-ix', 'hpc-rx', 'hpc-edu', 'hpc-fu', 'hpc-notes']);

  var noteText = ids.map(function(id) { return getField(id); }).filter(Boolean).join('\n\n').trim();
  if (noteText) return noteText;

  var liveText = getMicLiveText(true);
  if (liveText) {
    var target = getDictationTarget();
    if (target && !target.value.trim()) {
      target.value = liveText;
      target.classList.add('filled');
      aiVisitAutosaveDraft();
    }
    return liveText;
  }

  return '';
}

function getMicLiveText(requirePromotable) {
  if (requirePromotable && !micLivePromotable) {
    return '';
  }
  var live = document.getElementById('mic-live');
  var text = live ? live.textContent.trim() : '';
  if (!text && !requirePromotable && lastDictationText) {
    text = lastDictationText;
  }
  if (!text) return '';
  if (/^(Live transcript will appear here|Your note is still autosaved|Listening|Transcribing|Chunk \d+|Recording stopped|Whisper recorder error|Microphone unavailable|Could not start local Whisper)/i.test(text)) {
    return '';
  }
  return text;
}

function promoteMicLiveToField() {
  var liveText = getMicLiveText(true);
  if (!liveText) return;
  var target = getDictationTarget();
  if (!target) return;
  var current = target.value.trim();
  if (current && current.indexOf(liveText) !== -1) return;
  if (current && liveText.indexOf(current) !== -1) {
    target.value = liveText;
    target.classList.add('filled');
    aiVisitAutosaveDraft();
    return;
  }
  if (!current) {
    target.value = liveText;
    target.classList.add('filled');
    aiVisitAutosaveDraft();
  }
}

function buildFormattedReview(noteText) {
  var text = noteText.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  if (currentMode === 'soap') {
    return 'S - Subjective\n' + text + '\n\nO - Objective\n\nA - Assessment\n\nP - Plan';
  }
  if (currentMode === 'hpc') {
    return 'Presenting Complaint / History\n' + text + '\n\nExamination\n\nAssessment\n\nPlan';
  }
  return text;
}

function getActiveSoapFieldId() {
  var visible = document.querySelector('#panel-soap .sub-panel.visible textarea');
  return visible ? visible.id : 'soap-s';
}

function getActiveHpcFieldId() {
  var visible = document.querySelector('#panel-hpc .sub-panel.visible textarea');
  return visible ? visible.id : 'hpc-pc';
}

function fillField(id, val) {
  var el = document.getElementById(id);
  if (el && val) { el.value = val; el.classList.add('filled'); aiVisitAutosaveDraft(); }
}

function closeReview() { document.getElementById('review-box').classList.remove('visible'); }

function clearAll() {
  document.querySelectorAll('textarea').forEach(function(t) { t.value = ''; t.classList.remove('filled'); });
  lastDictationText = '';
  activeInterimText = '';
  activeInterimTargetId = '';
  lastChromeFinalText = '';
  lastChromeFinalTargetId = '';
  lastChromeFinalAt = 0;
  whisperQueue = [];
  whisperBusy = false;
  whisperChunkCount = 0;
  sttFatalErrorActive = false;
  transcriptSegments = [];
  lastProcessedSegmentCount = 0;
  pendingAITranscriptSegmentCount = 0;
  currentDictationStartSegmentCount = 0;
  dictationSessionOpen = false;
  micLivePromotable = false;
  syncFullTranscriptField();
  stopPersistentDictation();
  localStorage.removeItem(aiVisitStorageKey());
  var st = document.getElementById('mic-status');
  st.className = 'mic-status';
  st.textContent = 'Click mic to begin dictation';
  setMicLiveText('Live transcript will appear here\u2026');
  closeReview();
}

function saveDictation() {
  document.getElementById('footer-saved').textContent = 'Last saved: ' + new Date().toLocaleTimeString();
}

setProvider('local');


// ══════════════════════════════════════════
// LETTER GENERATION
// ══════════════════════════════════════════

function openLetterModal() {
  // Pre-fill today's date
  var today = new Date();
  var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  setLetterInputIfEmpty('ltr-date', today.getDate() + ' ' + months[today.getMonth()] + ' ' + today.getFullYear());
  populateFacilitySelect();
  populateReferringPhysicianSelect();
  fillLetterDefaultsFromOpenEmr();
  document.getElementById('letterModal').classList.add('visible');
  buildLetter();
}

function closeLetterModal() {
  document.getElementById('letterModal').classList.remove('visible');
}

function getField(id) {
  var el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

function setLetterInputIfEmpty(id, value) {
  var el = document.getElementById(id);
  if (el && !el.value.trim() && value) {
    el.value = value;
  }
}

function letterContext() {
  return window.AI_VISIT_LETTER_CONTEXT || { provider: {}, facility: {}, facilities: [], patient: {} };
}

function senderFacilities() {
  var ctx = letterContext();
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

function populateFacilitySelect() {
  var select = document.getElementById('ltr-facility');
  if (!select || select.getAttribute('data-loaded') === '1') return;
  var ctx = letterContext();
  var selectedId = ctx.facility && ctx.facility.id ? String(ctx.facility.id) : '';
  var selectedIndex = '';
  senderFacilities().forEach(function(facility, index) {
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

function selectedSenderFacility() {
  var ctx = letterContext();
  var select = document.getElementById('ltr-facility');
  var facilities = senderFacilities();
  if (!select || select.value === '') return ctx.facility || facilities[0] || {};
  var index = parseInt(select.value, 10);
  return facilities[index] || ctx.facility || {};
}

function selectSenderFacility() {
  buildLetter();
}

function referringProviders() {
  var ctx = letterContext();
  return ctx.referring_providers || [];
}

function populateReferringPhysicianSelect() {
  var select = document.getElementById('ltr-ref-select');
  if (!select || select.getAttribute('data-loaded') === '1') return;
  referringProviders().forEach(function(provider, index) {
    var option = document.createElement('option');
    var detail = [provider.practice, provider.specialty].filter(Boolean).join(' - ');
    option.value = String(index);
    option.textContent = provider.name + (detail ? ' (' + detail + ')' : '');
    select.appendChild(option);
  });
  select.setAttribute('data-loaded', '1');
}

function selectedReferringProvider() {
  var select = document.getElementById('ltr-ref-select');
  if (!select || select.value === '') return null;
  var index = parseInt(select.value, 10);
  var providers = referringProviders();
  return providers[index] || null;
}

function setFieldValue(id, value) {
  var el = document.getElementById(id);
  if (el) el.value = value || '';
}

function selectReferringPhysician() {
  var provider = selectedReferringProvider();
  if (!provider) return;
  setFieldValue('ltr-ref-name', provider.name || '');
  setFieldValue('ltr-ref-practice', provider.practice || '');
  setFieldValue('ltr-ref-address', provider.address || '');
  setFieldValue('ltr-ref-email', provider.email || '');
  buildLetter();
}

function fillLetterDefaultsFromOpenEmr() {
  var ctx = letterContext();
  setLetterInputIfEmpty('ltr-patient', ctx.patient && ctx.patient.name ? ctx.patient.name : '');
  setLetterInputIfEmpty('ltr-dob', ctx.patient && ctx.patient.dob ? ctx.patient.dob : '');
  setLetterInputIfEmpty('ltr-consultant', ctx.provider && ctx.provider.name ? ctx.provider.name : '');
  setLetterInputIfEmpty('ltr-specialty', ctx.provider && ctx.provider.specialty ? ctx.provider.specialty : '');
}

function practiceAddressLines(facility) {
  facility = facility || {};
  var lines = [];
  if (facility.street) lines.push(facility.street);
  var cityLine = [facility.city, facility.state, facility.postal_code].filter(Boolean).join(', ');
  if (cityLine) lines.push(cityLine);
  if (facility.country_code) lines.push(facility.country_code);
  return lines.join('\n');
}

function senderLines(providerName, facility) {
  facility = facility || {};
  var lines = [];
  var address = practiceAddressLines(facility);
  if (providerName) lines.push(providerName);
  if (facility.name) lines.push(facility.name);
  if (address) lines.push(address);
  if (facility.phone) lines.push('Telephone: ' + facility.phone);
  if (facility.fax) lines.push('Fax: ' + facility.fax);
  return lines.join('\n');
}

function buildLetter() {
  commitPendingInterim();
  promoteMicLiveToField();
  rebuildSegmentsFromFields();
  var ctx = letterContext();
  fillLetterDefaultsFromOpenEmr();
  var refName     = getField('ltr-ref-name')     || '[Referring Physician Name]';
  var refPractice = getField('ltr-ref-practice') || '[Practice / Hospital]';
  var refAddress  = getField('ltr-ref-address');
  var refEmail    = getField('ltr-ref-email');
  var salutation  = getField('ltr-salutation')   || 'Dear Dr.';
  var patient     = getField('ltr-patient')      || '[Patient Name]';
  var dob         = getField('ltr-dob')          || '[DOB]';
  var ltrDate     = getField('ltr-date')         || '[Date]';
  var consultant  = getField('ltr-consultant')   || (ctx.provider && ctx.provider.name ? ctx.provider.name : '[OpenEMR Provider]');
  var specialty   = getField('ltr-specialty')    || (ctx.provider && ctx.provider.specialty ? ctx.provider.specialty : 'Clinical Service');
  var ltrType     = getField('ltr-type')         || 'consult';
  var senderFacility = selectedSenderFacility();
  var senderBlock  = senderLines(consultant, senderFacility);
  var selectedRef = selectedReferringProvider();

  // Pull clinical content from whichever mode/tab has data
  var clinical = extractClinical();

  var salutationName = selectedRef && selectedRef.salutation_name ? selectedRef.salutation_name : refName.replace(/^Dr\.?\s*/i,'');
  var sal = salutation === 'Dear Dr.' ? salutation + ' ' + salutationName + ',' : salutation;
  var recipientBlock = [
    refName,
    refPractice,
    refAddress,
    refEmail ? 'Email: ' + refEmail : ''
  ].filter(Boolean).join('\n');

  var header =
    senderBlock + '\n\n' +
    ltrDate + '\n\n' +
    recipientBlock + '\n\n' +
    'Re: ' + patient + '   DOB: ' + dob + '\n' +
    '─────────────────────────────────────────\n\n' +
    sal + '\n\n';

  var body = '';

  if (ltrType === 'consult') {
    body =
      'Thank you for referring the above-named patient to our ' + specialty + ' service. I had the pleasure of reviewing ' +
      patient + ' on ' + ltrDate + '. I am pleased to report my findings and management plan below.\n\n';
    if (clinical.pc)      body += 'PRESENTING COMPLAINT\n' + clinical.pc + '\n\n';
    if (clinical.hx)      body += 'HISTORY\n' + clinical.hx + '\n\n';
    if (clinical.exam)    body += 'EXAMINATION\n' + clinical.exam + '\n\n';
    if (clinical.ix)      body += 'INVESTIGATIONS\n' + clinical.ix + '\n\n';
    if (clinical.assess)  body += 'ASSESSMENT\n' + clinical.assess + '\n\n';
    if (clinical.plan)    body += 'PLAN\n' + clinical.plan + '\n\n';
    body += 'I will continue to follow this patient and will keep you informed of further developments. ' +
      'Please do not hesitate to contact me should you have any queries.\n\n';
  } else if (ltrType === 'discharge') {
    body =
      'I am writing to inform you that ' + patient + ' has been discharged from our care following assessment on ' + ltrDate + '.\n\n';
    if (clinical.pc || clinical.hx) body += 'CLINICAL SUMMARY\n' + uniqueNonEmpty([clinical.pc, clinical.hx]).join('\n') + '\n\n';
    if (clinical.assess)  body += 'DIAGNOSIS\n' + clinical.assess + '\n\n';
    if (clinical.exam)    body += 'CLINICAL FINDINGS\n' + clinical.exam + '\n\n';
    if (clinical.ix)      body += 'INVESTIGATIONS\n' + clinical.ix + '\n\n';
    if (clinical.plan)    body += 'DISCHARGE PLAN / FOLLOW-UP\n' + clinical.plan + '\n\n';
    body += 'Please resume primary care of this patient. I would be grateful if you could ensure ongoing follow-up as outlined above.\n\n';
  } else if (ltrType === 'results') {
    body =
      'I am writing regarding the results of investigations arranged for ' + patient + '.\n\n';
    if (clinical.pc || clinical.hx) body += 'CLINICAL SUMMARY\n' + uniqueNonEmpty([clinical.pc, clinical.hx]).join('\n') + '\n\n';
    if (clinical.ix)      body += 'RESULTS\n' + clinical.ix + '\n\n';
    if (clinical.assess)  body += 'INTERPRETATION\n' + clinical.assess + '\n\n';
    if (clinical.plan)    body += 'RECOMMENDED ACTION\n' + clinical.plan + '\n\n';
    body += 'I would be grateful for your continued management of this patient in accordance with the above recommendations.\n\n';
  } else { // followup
    body =
      'I am writing following the review of ' + patient + ' in our ' + specialty + ' clinic on ' + ltrDate + '.\n\n';
    if (clinical.pc || clinical.hx) body += 'CLINICAL SUMMARY\n' + uniqueNonEmpty([clinical.pc, clinical.hx]).join('\n') + '\n\n';
    if (clinical.assess)  body += 'CURRENT STATUS\n' + clinical.assess + '\n\n';
    if (clinical.exam)    body += 'EXAMINATION TODAY\n' + clinical.exam + '\n\n';
    if (clinical.ix)      body += 'INVESTIGATIONS PENDING / REVIEWED\n' + clinical.ix + '\n\n';
    if (clinical.plan)    body += 'ONGOING PLAN\n' + clinical.plan + '\n\n';
    body += 'We will continue to see this patient in clinic. Thank you for your ongoing involvement in her care.\n\n';
  }

  var footer =
    'Yours sincerely,\n\n\n' +
    consultant + '\n' +
    specialty + '\n' +
    (senderFacility && senderFacility.name ? senderFacility.name + '\n\n' : '\n') +
    'Dictated and not read\n' +
    'Confidential — intended for named recipient only';

  document.getElementById('letter-text').value = header + body + footer;
  renderLetterPreview();
}

function escapeHtml(text) {
  return String(text || '').replace(/[&<>"']/g, function(ch) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
  });
}

function letterLogoHtml() {
  var ctx = letterContext();
  if (!ctx.logo_url) return '';
  return '<img class="letter-paper-logo" src="' + escapeHtml(ctx.logo_url) + '" alt="Practice logo">';
}

function letterHtmlDocumentBody() {
  var text = getField('letter-text');
  var parts = String(text || '').split(/\n{2,}/);
  var fromBlock = parts.shift() || '';
  var rest = parts.join('\n\n');
  return '<div class="letter-paper-header"><div class="letter-paper-from">' +
    escapeHtml(fromBlock).replace(/\n/g, '<br>') +
    '</div>' + letterLogoHtml() + '</div>' +
    '<div class="letter-paper-body">' + escapeHtml(rest).replace(/\n/g, '<br>') + '</div>';
}

function renderLetterPreview() {
  var paper = document.getElementById('letter-paper');
  if (paper) {
    paper.innerHTML = letterHtmlDocumentBody();
  }
}

function extractClinical() {
  // Collects relevant fields from all modes plus any pending AI review fields.
  var d = {};
  commitPendingInterim();
  rebuildSegmentsFromFields();

  var plainMain = fieldOrPending('pl-main', 'pl_main');
  var plainNotes = fieldOrPending('pl-notes', 'pl_notes');
  var structuredValues = [
    fieldOrPending('hpc-pc', 'hpc_pc'), fieldOrPending('hpc-hpi', 'hpc_hpi'), fieldOrPending('hpc-onset', 'hpc_onset'),
    fieldOrPending('hpc-char', 'hpc_char'), fieldOrPending('hpc-rad', 'hpc_rad'),
    fieldOrPending('hpc-mod', 'hpc_mod'), fieldOrPending('hpc-assoc', 'hpc_assoc'),
    fieldOrPending('hpc-obhx', 'hpc_obhx'), fieldOrPending('hpc-ros', 'hpc_ros'),
    fieldOrPending('hpc-pmhx', 'hpc_pmhx'), fieldOrPending('hpc-meds', 'hpc_meds'),
    fieldOrPending('hpc-fhx', 'hpc_fhx'), fieldOrPending('hpc-ix-to-date', 'hpc_ix_to_date'),
    fieldOrPending('ex-vitals', 'ex_vitals'), fieldOrPending('ex-gen', 'ex_gen'),
    fieldOrPending('ex-resp', 'ex_resp'), fieldOrPending('ex-cvs', 'ex_cvs'),
    fieldOrPending('ex-abdo', 'ex_abdo'), fieldOrPending('ex-pelvis', 'ex_pelvis'),
    fieldOrPending('ex-cns', 'ex_cns'), fieldOrPending('hpc-dx', 'hpc_dx'),
    fieldOrPending('hpc-ddx', 'hpc_ddx'), fieldOrPending('hpc-ix', 'hpc_ix'),
    fieldOrPending('hpc-rx', 'hpc_rx'), fieldOrPending('hpc-edu', 'hpc_edu'),
    fieldOrPending('hpc-fu', 'hpc_fu'), fieldOrPending('soap-s', 'soap_s'),
    fieldOrPending('soap-vitals', 'soap_vitals'), fieldOrPending('soap-gen', 'soap_gen'),
    fieldOrPending('soap-resp', 'soap_resp'), fieldOrPending('soap-cvs', 'soap_cvs'),
    fieldOrPending('soap-abdo', 'soap_abdo'), fieldOrPending('soap-pelvis', 'soap_pelvis'),
    fieldOrPending('soap-cns', 'soap_cns'), fieldOrPending('soap-ix', 'soap_ix'),
    fieldOrPending('soap-adx', 'soap_adx'), fieldOrPending('soap-ddx', 'soap_ddx'),
    fieldOrPending('soap-pix', 'soap_pix'), fieldOrPending('soap-rx', 'soap_rx'),
    fieldOrPending('soap-edu', 'soap_edu'), fieldOrPending('soap-fu', 'soap_fu')
  ];
  var hasStructured = structuredValues.some(function(value) { return !!value; });

  if (!hasStructured) {
    var freshTranscript = getUnprocessedTranscriptText();
    d.pc = '';
    d.hx = plainMain || freshTranscript || (lastProcessedSegmentCount === 0 ? getFullTranscriptText() : '');
    d.exam = '';
    d.ix = '';
    d.assess = plainNotes;
    d.plan = '';
    d.raw = [d.hx, d.assess].filter(Boolean).join('\n\n');
    return d;
  }

  d.pc = fieldOrPending('hpc-pc', 'hpc_pc');

  var hx_parts = uniqueNonEmpty([
    fieldOrPending('soap-s', 'soap_s'),
    fieldOrPending('hpc-hpi', 'hpc_hpi') ? 'HPC details: ' + fieldOrPending('hpc-hpi', 'hpc_hpi') : '',
    fieldOrPending('hpc-onset', 'hpc_onset') ? 'Onset/Duration: ' + fieldOrPending('hpc-onset', 'hpc_onset') : '',
    fieldOrPending('hpc-char', 'hpc_char') ? 'Character/Severity: ' + fieldOrPending('hpc-char', 'hpc_char') : '',
    fieldOrPending('hpc-rad', 'hpc_rad') ? 'Radiation/Location: ' + fieldOrPending('hpc-rad', 'hpc_rad') : '',
    fieldOrPending('hpc-mod', 'hpc_mod') ? 'Relieving/Aggravating: ' + fieldOrPending('hpc-mod', 'hpc_mod') : '',
    fieldOrPending('hpc-assoc', 'hpc_assoc') ? 'Associated symptoms: ' + fieldOrPending('hpc-assoc', 'hpc_assoc') : '',
    fieldOrPending('hpc-obhx', 'hpc_obhx') ? 'OB/GYN history: ' + fieldOrPending('hpc-obhx', 'hpc_obhx') : '',
    fieldOrPending('hpc-ros', 'hpc_ros') ? 'Review of systems: ' + fieldOrPending('hpc-ros', 'hpc_ros') : '',
    fieldOrPending('hpc-pmhx', 'hpc_pmhx') ? 'PMHx: ' + fieldOrPending('hpc-pmhx', 'hpc_pmhx') : '',
    fieldOrPending('hpc-meds', 'hpc_meds') ? 'Medications/Allergies: ' + fieldOrPending('hpc-meds', 'hpc_meds') : '',
    fieldOrPending('hpc-fhx', 'hpc_fhx') ? 'Family history: ' + fieldOrPending('hpc-fhx', 'hpc_fhx') : '',
    fieldOrPending('hpc-ix-to-date', 'hpc_ix_to_date') ? 'Investigations to date: ' + fieldOrPending('hpc-ix-to-date', 'hpc_ix_to_date') : ''
  ]);
  d.hx = hx_parts.join('\n') || plainMain;

  var ex_parts = uniqueNonEmpty([
    firstNonEmpty([fieldOrPending('ex-vitals', 'ex_vitals'), fieldOrPending('soap-vitals', 'soap_vitals')]) ? 'Vitals: ' + firstNonEmpty([fieldOrPending('ex-vitals', 'ex_vitals'), fieldOrPending('soap-vitals', 'soap_vitals')]) : '',
    firstNonEmpty([fieldOrPending('ex-gen', 'ex_gen'), fieldOrPending('soap-gen', 'soap_gen')]) ? 'General: ' + firstNonEmpty([fieldOrPending('ex-gen', 'ex_gen'), fieldOrPending('soap-gen', 'soap_gen')]) : '',
    firstNonEmpty([fieldOrPending('ex-resp', 'ex_resp'), fieldOrPending('soap-resp', 'soap_resp')]) ? 'Respiratory: ' + firstNonEmpty([fieldOrPending('ex-resp', 'ex_resp'), fieldOrPending('soap-resp', 'soap_resp')]) : '',
    firstNonEmpty([fieldOrPending('ex-cvs', 'ex_cvs'), fieldOrPending('soap-cvs', 'soap_cvs')]) ? 'CVS: ' + firstNonEmpty([fieldOrPending('ex-cvs', 'ex_cvs'), fieldOrPending('soap-cvs', 'soap_cvs')]) : '',
    firstNonEmpty([fieldOrPending('ex-abdo', 'ex_abdo'), fieldOrPending('soap-abdo', 'soap_abdo')]) ? 'Abdomen: ' + firstNonEmpty([fieldOrPending('ex-abdo', 'ex_abdo'), fieldOrPending('soap-abdo', 'soap_abdo')]) : '',
    firstNonEmpty([fieldOrPending('ex-pelvis', 'ex_pelvis'), fieldOrPending('soap-pelvis', 'soap_pelvis')]) ? 'Pelvis: ' + firstNonEmpty([fieldOrPending('ex-pelvis', 'ex_pelvis'), fieldOrPending('soap-pelvis', 'soap_pelvis')]) : '',
    firstNonEmpty([fieldOrPending('ex-cns', 'ex_cns'), fieldOrPending('soap-cns', 'soap_cns')]) ? 'CNS: ' + firstNonEmpty([fieldOrPending('ex-cns', 'ex_cns'), fieldOrPending('soap-cns', 'soap_cns')]) : ''
  ]);
  d.exam = ex_parts.join('\n');

  d.ix = firstNonEmpty([fieldOrPending('hpc-ix-to-date', 'hpc_ix_to_date'), fieldOrPending('hpc-ix', 'hpc_ix'), fieldOrPending('soap-ix', 'soap_ix'), fieldOrPending('soap-pix', 'soap_pix')]);
  d.assess = uniqueNonEmpty([
    fieldOrPending('hpc-dx', 'hpc_dx'),
    fieldOrPending('soap-adx', 'soap_adx'),
    fieldOrPending('hpc-ddx', 'hpc_ddx') ? 'DDx: ' + fieldOrPending('hpc-ddx', 'hpc_ddx') : '',
    fieldOrPending('soap-ddx', 'soap_ddx') ? 'DDx: ' + fieldOrPending('soap-ddx', 'soap_ddx') : '',
    plainNotes
  ]).join('\n');
  d.plan = uniqueNonEmpty([
    fieldOrPending('hpc-ix', 'hpc_ix') ? 'Investigations requested: ' + fieldOrPending('hpc-ix', 'hpc_ix') : '',
    fieldOrPending('soap-pix', 'soap_pix') ? 'Investigations: ' + fieldOrPending('soap-pix', 'soap_pix') : '',
    firstNonEmpty([fieldOrPending('hpc-rx', 'hpc_rx'), fieldOrPending('soap-rx', 'soap_rx')]) ? 'Treatment: ' + firstNonEmpty([fieldOrPending('hpc-rx', 'hpc_rx'), fieldOrPending('soap-rx', 'soap_rx')]) : '',
    firstNonEmpty([fieldOrPending('hpc-edu', 'hpc_edu'), fieldOrPending('soap-edu', 'soap_edu')]) ? 'Counselling: ' + firstNonEmpty([fieldOrPending('hpc-edu', 'hpc_edu'), fieldOrPending('soap-edu', 'soap_edu')]) : '',
    firstNonEmpty([fieldOrPending('hpc-fu', 'hpc_fu'), fieldOrPending('soap-fu', 'soap_fu')]) ? 'Follow-up: ' + firstNonEmpty([fieldOrPending('hpc-fu', 'hpc_fu'), fieldOrPending('soap-fu', 'soap_fu')]) : ''
  ]).join('\n');
  d.raw = uniqueNonEmpty([d.pc, d.hx, d.exam, d.ix, d.assess, d.plan, plainMain]).join('\n\n');
  return d;
}

function fieldOrPending(id, key) {
  var value = getField(id);
  if (value) return value;
  if (pendingAIFields && key && pendingAIFields[key]) {
    return String(pendingAIFields[key]).trim();
  }
  return '';
}

function firstNonEmpty(values) {
  for (var i = 0; i < values.length; i++) {
    if (values[i]) return values[i];
  }
  return '';
}

function uniqueNonEmpty(values) {
  var seen = {};
  return values.map(function(value) {
    return cleanDictationText(value || '');
  }).filter(function(value) {
    if (!value || seen[value]) return false;
    seen[value] = true;
    return true;
  });
}

function copyLetter() {
  var el = document.getElementById('letter-text');
  el.select();
  document.execCommand('copy');
  var note = document.getElementById('letter-copied');
  note.textContent = '\u2714 Copied to clipboard';
  setTimeout(function() { note.textContent = ''; }, 2500);
}

function printLetter() {
  renderLetterPreview();
  var win = window.open('', '_blank');
  win.document.write(
    '<html><head><title>Referral Letter</title>' +
    '<style>body{font-family:Georgia,serif;font-size:13px;line-height:1.7;margin:40px 60px;color:#222;}' +
    '.letter-paper-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:24px;}' +
    '.letter-paper-from{white-space:normal;line-height:1.55;}' +
    '.letter-paper-logo{max-width:180px;max-height:82px;object-fit:contain;display:block;margin:0 0 0 24px;}' +
    '.letter-paper-body{white-space:normal;}' +
    '@media print{body{margin:20mm;}}</style></head>' +
    '<body>' + letterHtmlDocumentBody() +
    '<script>window.onload=function(){window.print();}<\/script></body></html>'
  );
  win.document.close();
}


// OpenEMR persistence adapter. The original mockup remains intact; this layer
// serializes every editable control into the encounter form record.
function aiVisitCollectPayload() {
  commitPendingInterim();
  promoteMicLiveToField();
  var payload = {
    controls: {},
    currentMode: currentMode,
    recordingWanted: recordingWanted,
    activeDictationTargetId: activeDictationTargetId,
    lastDictationText: lastDictationText,
    transcriptSegments: transcriptSegments,
    fullTranscript: getFullTranscriptText(),
    lastProcessedSegmentCount: lastProcessedSegmentCount,
    currentDictationStartSegmentCount: currentDictationStartSegmentCount,
    dictationSessionOpen: dictationSessionOpen,
    savedAt: Date.now()
  };
  document.querySelectorAll('textarea, input, select').forEach(function(el) {
    if (!el.id || el.type === 'hidden') return;
    payload.controls[el.id] = el.value;
  });
  var providerSelect = document.querySelector('.prov-select');
  payload.provider = normalizeProviderValue(providerSelect ? providerSelect.value : 'local');
  return payload;
}

function aiVisitChartFieldIds(mode) {
  if (mode === 'soap') {
    return ['soap-s', 'soap-vitals', 'soap-gen', 'soap-resp', 'soap-cvs', 'soap-abdo', 'soap-pelvis', 'soap-cns', 'soap-ix', 'soap-adx', 'soap-ddx', 'soap-pix', 'soap-rx', 'soap-edu', 'soap-fu', 'soap-notes'];
  }
  if (mode === 'hpc') {
    return ['hpc-pc', 'hpc-hpi', 'hpc-onset', 'hpc-char', 'hpc-rad', 'hpc-mod', 'hpc-assoc', 'hpc-obhx', 'hpc-ros', 'hpc-pmhx', 'hpc-meds', 'hpc-fhx', 'hpc-ix-to-date', 'ex-vitals', 'ex-gen', 'ex-resp', 'ex-cvs', 'ex-abdo', 'ex-pelvis', 'ex-cns', 'hpc-dx', 'hpc-ddx', 'hpc-ix', 'hpc-rx', 'hpc-edu', 'hpc-fu', 'hpc-notes'];
  }
  return ['pl-main', 'pl-notes'];
}

function aiVisitCollectChartPayload() {
  var payload = aiVisitCollectPayload();
  var chartControls = {};
  aiVisitChartFieldIds(payload.currentMode || currentMode || 'plain').forEach(function(id) {
    if (payload.controls[id]) {
      chartControls[id] = payload.controls[id];
    }
  });
  payload.controls = chartControls;
  payload.recordType = 'chart';
  payload.reviewText = '';
  payload.fullTranscript = '';
  payload.transcriptSegments = [];
  payload.lastDictationText = '';
  payload.activeDictationTargetId = '';
  payload.recordingWanted = false;
  payload.dictationSessionOpen = false;
  payload.currentDictationStartSegmentCount = 0;
  payload.lastProcessedSegmentCount = 0;
  return payload;
}

function aiVisitRestorePayload(payload, options) {
  if (!payload || !payload.controls) return;
  options = options || {};
  Object.keys(payload.controls).forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.value = payload.controls[id];
    if (el.tagName === 'TEXTAREA' && el.value) el.classList.add('filled');
  });
  if (payload.currentMode) setMode(payload.currentMode);
  if (payload.activeDictationTargetId) activeDictationTargetId = payload.activeDictationTargetId;
  if (payload.lastDictationText) lastDictationText = payload.lastDictationText;
  if (payload.transcriptSegments && payload.transcriptSegments.length) {
    transcriptSegments = payload.transcriptSegments.filter(function(segment) {
      return segment && segment.text;
    });
    syncFullTranscriptField();
  } else if (payload.fullTranscript) {
    transcriptSegments = payload.fullTranscript.split(/\n{2,}/).map(function(text, i) {
      return { text: cleanDictationText(text), source: 'restored', index: i + 1, at: Date.now() };
    }).filter(function(segment) { return segment.text; });
    syncFullTranscriptField();
  }
  if (typeof payload.lastProcessedSegmentCount === 'number') {
    lastProcessedSegmentCount = payload.lastProcessedSegmentCount;
  } else {
    lastProcessedSegmentCount = options.localDraft ? 0 : transcriptSegments.length;
  }
  if (typeof payload.currentDictationStartSegmentCount === 'number') {
    currentDictationStartSegmentCount = payload.currentDictationStartSegmentCount;
  } else {
    currentDictationStartSegmentCount = lastProcessedSegmentCount;
  }
  dictationSessionOpen = !!payload.dictationSessionOpen;
  clampProcessedSegmentCount();
  if (payload.provider) {
    payload.provider = normalizeProviderValue(payload.provider);
    var providerSelect = document.querySelector('.prov-select');
    if (providerSelect) providerSelect.value = payload.provider;
    setProvider(payload.provider);
  }
  if (payload.recordingWanted) {
    recordingWanted = true;
    setRecordingUi(false, 'Dictation was active \u2014 click mic to resume, or return to this tab to restart');
  }
}

function aiVisitStorageKey() {
  if (!window.AI_VISIT_FORM_STORAGE_KEY && window.console && console.warn) {
    console.warn('AI Visit Forms: missing AI_VISIT_FORM_STORAGE_KEY; using generic draft key.');
  }
  return window.AI_VISIT_FORM_STORAGE_KEY || 'ai_visit_forms_draft';
}

function aiVisitAutosaveDraft() {
  clearTimeout(window.aiVisitAutosaveTimer);
  window.aiVisitAutosaveTimer = setTimeout(function() {
    aiVisitAutosaveDraftNow();
  }, 150);
}

function aiVisitAutosaveDraftNow() {
  var payload = aiVisitCollectPayload();
  try {
    localStorage.setItem(aiVisitStorageKey(), JSON.stringify(payload));
    lastAutosaveAt = payload.savedAt;
    var saved = document.getElementById('footer-saved');
    if (saved) saved.textContent = 'Autosaved: ' + new Date(payload.savedAt).toLocaleTimeString();
  } catch (e) {}
}

function aiVisitRestoreLocalDraft() {
  try {
    var raw = localStorage.getItem(aiVisitStorageKey());
    if (!raw) return;
    var payload = JSON.parse(raw);
    if (!payload || !payload.savedAt) return;
    if (!window.AI_VISIT_FORM_PAYLOAD || !window.AI_VISIT_FORM_PAYLOAD.savedAt || payload.savedAt >= window.AI_VISIT_FORM_PAYLOAD.savedAt) {
      aiVisitRestorePayload(payload, { localDraft: true });
      var saved = document.getElementById('footer-saved');
      if (saved) saved.textContent = 'Recovered draft: ' + new Date(payload.savedAt).toLocaleTimeString();
    }
  } catch (e) {}
}

function aiVisitContainsDemoData(raw) {
  return /__DEMO_MOCKUP_DATA__/i.test(raw || '');
}

function saveDictation() {
  commitPendingInterim();
  if (pendingAIFields) {
    applyAIFields(pendingAIFields);
    pendingAIFields = null;
  }
  closeReview();
  var payload = aiVisitCollectChartPayload();
  document.getElementById('payload_json').value = JSON.stringify(payload);
  document.getElementById('dictation_mode').value = payload.currentMode || currentMode || 'plain';
  document.getElementById('llm_provider').value = payload.provider || 'local';
  document.getElementById('letter_text_value').value = '';
  localStorage.removeItem(aiVisitStorageKey());
  document.getElementById('ai-visit-form').submit();
}

document.addEventListener('DOMContentLoaded', function() {
  populateFacilitySelect();
  populateReferringPhysicianSelect();
  if (window.AI_VISIT_FORM_PAYLOAD) {
    aiVisitRestorePayload(window.AI_VISIT_FORM_PAYLOAD, { storedRecord: true });
  }
  aiVisitRestoreLocalDraft();
  if (window.AI_VISIT_DEFAULT_PROVIDER) {
    window.AI_VISIT_DEFAULT_PROVIDER = normalizeProviderValue(window.AI_VISIT_DEFAULT_PROVIDER);
    var defaultProviderSelect = document.querySelector('.prov-select');
    if (defaultProviderSelect) defaultProviderSelect.value = window.AI_VISIT_DEFAULT_PROVIDER;
    setProvider(window.AI_VISIT_DEFAULT_PROVIDER);
  }
  document.querySelectorAll('textarea, input, select').forEach(function(el) {
    if (!el.id || el.type === 'hidden') return;
    el.addEventListener('input', aiVisitAutosaveDraft);
    el.addEventListener('change', aiVisitAutosaveDraft);
    if (el.tagName === 'TEXTAREA') {
      el.addEventListener('focus', function() { activeDictationTargetId = el.id; aiVisitAutosaveDraft(); });
    }
  });
  document.addEventListener('visibilitychange', function() {
    commitPendingInterim();
    promoteMicLiveToField();
    aiVisitAutosaveDraft();
    if (!document.hidden && recordingWanted) {
      if (recognition) {
        try { recognition.abort(); } catch (e) {}
        recognition = null;
      }
      startPersistentDictation();
    }
  });
  window.addEventListener('pagehide', function() { commitPendingInterim(); promoteMicLiveToField(); aiVisitAutosaveDraftNow(); });
  window.addEventListener('beforeunload', function() { commitPendingInterim(); promoteMicLiveToField(); aiVisitAutosaveDraftNow(); });
  setInterval(function() {
    if (recordingWanted) {
      promoteMicLiveToField();
    }
  }, 2000);
});
