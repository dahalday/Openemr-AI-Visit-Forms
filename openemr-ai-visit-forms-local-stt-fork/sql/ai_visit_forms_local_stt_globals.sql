INSERT INTO globals (gl_name, gl_index, gl_value) VALUES
('ai_dictation_stt_engine', 0, 'faster_whisper_auto'),
('ai_dictation_faster_whisper_endpoint', 0, 'http://127.0.0.1:9010'),
('ai_dictation_faster_whisper_model', 0, 'large-v3-turbo'),
('ai_dictation_faster_whisper_device', 0, 'auto'),
('ai_dictation_faster_whisper_compute_type', 0, 'auto'),
('ai_dictation_deepgram_api_key', 0, ''),
('ai_dictation_deepgram_model', 0, 'nova-3-medical'),
('ai_dictation_deepgram_custom_model', 0, ''),
('ai_dictation_deepgram_keyterms', 0, 'pelvic inflammatory disease, tubo-ovarian abscess, cervical motion tenderness, adnexal tenderness, CA-125, endometrial biopsy, vulvar lesion, labia majora, suprapubic tenderness, postmenopausal bleeding'),
('ai_dictation_deepgram_smart_format', 0, '1'),
('ai_dictation_deepgram_dictation', 0, '1'),
('ai_dictation_deepgram_measurements', 0, '1')
ON DUPLICATE KEY UPDATE gl_value = IF(gl_value = '', VALUES(gl_value), gl_value);
