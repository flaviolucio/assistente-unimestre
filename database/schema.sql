-- Assistente UNIMESTRE: estrutura do banco (somente tabelas e colunas, sem dados).
-- Gerado a partir das migrations em database/migrations.
-- Para instalar o sistema, prefira: php database/migrate.php

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_providers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `model` varchar(150) DEFAULT NULL,
  `api_key_encrypted` text DEFAULT NULL,
  `base_url` varchar(500) DEFAULT NULL,
  `temperature` decimal(3,2) NOT NULL DEFAULT 0.20,
  `max_tokens` int(10) unsigned DEFAULT NULL,
  `timeout_seconds` int(10) unsigned NOT NULL DEFAULT 60,
  `status` varchar(20) NOT NULL DEFAULT 'inactive',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `priority` int(10) unsigned NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_providers_provider` (`provider`),
  KEY `idx_ai_providers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(60) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user` (`user_id`),
  KEY `idx_audit_logs_action` (`action`),
  KEY `idx_audit_logs_created_at` (`created_at`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_limits_user` (`user_id`),
  CONSTRAINT `fk_chat_limits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_request_guards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `client_request_id` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_request_guards_user_client` (`user_id`,`client_request_id`),
  KEY `idx_chat_request_guards_created` (`created_at`),
  CONSTRAINT `fk_chat_request_guards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conversations_user` (`user_id`),
  KEY `idx_conversations_favorite` (`user_id`,`is_favorite`),
  CONSTRAINT `fk_conversations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `diagnostic_hypotheses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `diagnostic_session_id` int(10) unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `title_key` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('possible','supported','weakened','rejected','confirmed') NOT NULL DEFAULT 'possible',
  `evidence_summary` text DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_diagnostic_hypotheses_key` (`diagnostic_session_id`,`title_key`),
  CONSTRAINT `fk_diagnostic_hypotheses_session` FOREIGN KEY (`diagnostic_session_id`) REFERENCES `diagnostic_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `diagnostic_hypothesis_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `diagnostic_hypothesis_id` int(10) unsigned NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) NOT NULL,
  `message_id` int(10) unsigned DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_diagnostic_hypothesis_events_hypothesis` (`diagnostic_hypothesis_id`),
  KEY `fk_diagnostic_hypothesis_events_message` (`message_id`),
  CONSTRAINT `fk_diagnostic_hypothesis_events_hypothesis` FOREIGN KEY (`diagnostic_hypothesis_id`) REFERENCES `diagnostic_hypotheses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_diagnostic_hypothesis_events_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `diagnostic_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `status` enum('open','investigating','solution_proposed','escalation_needed','escalated','resolved','abandoned') NOT NULL DEFAULT 'open',
  `original_problem` text NOT NULL,
  `identified_module` varchar(150) DEFAULT NULL,
  `identified_context` varchar(255) DEFAULT NULL,
  `current_summary` text DEFAULT NULL,
  `current_stage` varchar(30) NOT NULL DEFAULT 'collecting_context',
  `confirmed_cause` text DEFAULT NULL,
  `recommended_action` text DEFAULT NULL,
  `questions_json` longtext DEFAULT NULL,
  `sources_json` longtext DEFAULT NULL,
  `escalation_json` longtext DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `escalated_by` int(10) unsigned DEFAULT NULL,
  `escalation_reason` varchar(255) DEFAULT NULL,
  `escalation_summary_json` longtext DEFAULT NULL,
  `external_reference` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_diagnostic_sessions_conversation` (`conversation_id`,`status`),
  KEY `fk_diagnostic_sessions_escalated_by` (`escalated_by`),
  CONSTRAINT `fk_diagnostic_sessions_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_diagnostic_sessions_escalated_by` FOREIGN KEY (`escalated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `rating` enum('util','nao_util') NOT NULL DEFAULT 'util',
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feedback_message_user` (`message_id`,`user_id`),
  KEY `idx_feedback_message` (`message_id`),
  KEY `idx_feedback_user` (`user_id`),
  CONSTRAINT `fk_feedback_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `internal_solutions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `problem` text NOT NULL,
  `solution` text NOT NULL,
  `category` varchar(150) DEFAULT NULL,
  `tags` varchar(300) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_internal_solutions_status` (`status`),
  KEY `idx_internal_solutions_category` (`category`),
  KEY `idx_internal_solutions_created_by` (`created_by`),
  KEY `idx_internal_solutions_title` (`title`),
  CONSTRAINT `fk_internal_solutions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `knowledge_chunks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `chunk_index` int(11) NOT NULL,
  `content` text NOT NULL,
  `token_estimate` int(10) unsigned NOT NULL DEFAULT 0,
  `search_text` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_knowledge_chunks_document_index` (`document_id`,`chunk_index`),
  KEY `idx_knowledge_chunks_document` (`document_id`),
  FULLTEXT KEY `ft_knowledge_chunks_content_search` (`content`,`search_text`),
  CONSTRAINT `fk_knowledge_chunks_document` FOREIGN KEY (`document_id`) REFERENCES `knowledge_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `knowledge_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `url` varchar(500) DEFAULT NULL,
  `category` varchar(150) DEFAULT NULL,
  `subcategory` varchar(150) DEFAULT NULL,
  `content` longtext NOT NULL,
  `content_hash` char(64) NOT NULL,
  `origin` varchar(40) NOT NULL DEFAULT 'unimestre_oficial',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `collected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_knowledge_documents_slug` (`slug`),
  UNIQUE KEY `uq_knowledge_documents_source_hash` (`source_id`,`content_hash`),
  KEY `idx_knowledge_documents_status` (`status`),
  KEY `idx_knowledge_documents_category` (`category`),
  FULLTEXT KEY `ft_knowledge_documents_title_content` (`title`,`content`),
  CONSTRAINT `fk_knowledge_documents_source` FOREIGN KEY (`source_id`) REFERENCES `knowledge_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `knowledge_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `type` enum('official','internal','internal_procedure','resolved_case','vendor_support') NOT NULL,
  `base_url` varchar(500) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_knowledge_sources_name` (`name`),
  KEY `idx_knowledge_sources_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email` (`email`,`attempted_at`),
  KEY `idx_login_attempts_ip` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned NOT NULL,
  `document_id` int(10) unsigned DEFAULT NULL,
  `chunk_id` int(10) unsigned DEFAULT NULL,
  `solution_id` int(10) unsigned DEFAULT NULL,
  `source_type` enum('official','internal','internal_procedure','vendor_support','resolved_case') NOT NULL DEFAULT 'official',
  `relevance_score` decimal(6,4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_message_sources_message` (`message_id`),
  KEY `fk_message_sources_document` (`document_id`),
  KEY `fk_message_sources_chunk` (`chunk_id`),
  KEY `idx_message_sources_solution` (`solution_id`),
  CONSTRAINT `fk_message_sources_chunk` FOREIGN KEY (`chunk_id`) REFERENCES `knowledge_chunks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_message_sources_document` FOREIGN KEY (`document_id`) REFERENCES `knowledge_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_message_sources_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_sources_solution` FOREIGN KEY (`solution_id`) REFERENCES `internal_solutions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `content` longtext NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `tokens_input` int(10) unsigned DEFAULT NULL,
  `tokens_output` int(10) unsigned DEFAULT NULL,
  `tokens_total` int(10) unsigned DEFAULT NULL,
  `finish_reason` varchar(30) DEFAULT NULL,
  `truncated` tinyint(1) NOT NULL DEFAULT 0,
  `structured_json` longtext DEFAULT NULL,
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `request_id` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_messages_conversation` (`conversation_id`,`created_at`),
  CONSTRAINT `fk_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `version` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  `batch` int(11) NOT NULL DEFAULT 1,
  `ran_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`),
  KEY `idx_migrations_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seeds` (
  `version` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  `ran_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'general',
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_system_settings_key` (`setting_key`),
  KEY `idx_system_settings_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','tecnico','usuario') NOT NULL DEFAULT 'usuario',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

