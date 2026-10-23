-- PageForge (pageforge) Baseline Schema (v0.1.0)
-- Single authoritative baseline DDL source for pageforge_* tables.
-- Tenant-scoped throughout; document trees live in pageforge_revisions.

CREATE TABLE IF NOT EXISTS `pageforge_pages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(191) NOT NULL,
    `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `kind` ENUM('page','part','popup','global') NOT NULL DEFAULT 'page',
    `part` VARCHAR(20) NULL DEFAULT NULL,
    `active_draft_revision_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `published_revision_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `settings_json` MEDIUMTEXT NULL,
    `published_at` DATETIME NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pageforge_pages_tenant_uuid` (`tenant_id`, `uuid`),
    UNIQUE KEY `uq_pageforge_pages_tenant_slug` (`tenant_id`, `slug`),
    KEY `idx_pageforge_pages_tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pageforge_revisions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL,
    `revision_kind` ENUM('autosave','manual','publish','rollback','import') NOT NULL DEFAULT 'manual',
    `schema_version` VARCHAR(16) NOT NULL DEFAULT '1.0',
    `document_json` LONGTEXT NOT NULL,
    `summary` VARCHAR(255) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pageforge_revisions_page_number` (`page_id`, `revision_number`),
    KEY `idx_pageforge_revisions_tenant_page` (`tenant_id`, `page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pageforge_compilations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `html` LONGTEXT NOT NULL,
    `css` LONGTEXT NOT NULL,
    `compiled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pageforge_compilations_page` (`page_id`),
    KEY `idx_pageforge_compilations_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
