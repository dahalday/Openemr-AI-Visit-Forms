CREATE TABLE IF NOT EXISTS `form_ai_visit_forms` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `date` datetime DEFAULT NULL,
  `pid` bigint(20) DEFAULT NULL,
  `groupname` varchar(255) DEFAULT NULL,
  `user` varchar(255) DEFAULT NULL,
  `authorized` tinyint(4) DEFAULT 0,
  `activity` tinyint(4) DEFAULT 1,
  `dictation_mode` varchar(16) NOT NULL DEFAULT 'plain',
  `llm_provider` varchar(32) NOT NULL DEFAULT 'local',
  `payload_json` longtext,
  `letter_text` longtext,
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
