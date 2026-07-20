-- ============================================================
--  CHTM COOKS — Database Schema
--  Dialect  : MySQL 8.0+ / MariaDB 10.5+
--  Encoding : UTF-8 (utf8mb4)
--  Collation: utf8mb4_unicode_ci
--
--  Conventions
--  ──────────────────────────────────────────────────────────
--  • All surrogate PKs are BIGINT UNSIGNED AUTO_INCREMENT.
--  • ENUM columns enforce domain at the storage layer.
--  • Soft-delete columns (deleted_at) use Laravel SoftDeletes.
--  • Lifecycle timestamps (approved_at, returned_at, …) are
--    nullable and set exactly once by application logic.
--  • JSON columns require MySQL 5.7.8+ / MariaDB 10.2.7+.
--  • Tables are ordered so that every FK target is created
--    before the table that references it.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;


-- ============================================================
--  SECTION 1 — USERS & AUTH
-- ============================================================

-- ------------------------------------------------------------
--  users
--  Central identity record. The `role` column drives all
--  authorization logic across the system.
-- ------------------------------------------------------------
CREATE TABLE `users` (
    `id`                            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `email`                         VARCHAR(255)        NOT NULL,
    `password`                      VARCHAR(255)        NOT NULL,
    `role`                          ENUM(
                                        'student',
                                        'custodian',
                                        'instructor',
                                        'superadmin',
                                        'admin'
                                    )                   NOT NULL,
    `first_name`                    VARCHAR(255)        NOT NULL,
    `last_name`                     VARCHAR(255)        NOT NULL,
    `profile_photo_url`             VARCHAR(1000)       NULL        DEFAULT NULL,
    `profile_photo_public_id`       VARCHAR(255)        NULL        DEFAULT NULL,
    `is_active`                     TINYINT(1)          NOT NULL    DEFAULT 1,
    `last_login`                    TIMESTAMP           NULL        DEFAULT NULL,
    `email_verified`                TINYINT(1)          NOT NULL    DEFAULT 0,
    `email_verification_token`      VARCHAR(255)        NULL        DEFAULT NULL,
    `email_verification_expires`    TIMESTAMP           NULL        DEFAULT NULL,
    `password_reset_token`          VARCHAR(255)        NULL        DEFAULT NULL,
    `password_reset_expires`        TIMESTAMP           NULL        DEFAULT NULL,
    -- Applicable to students only
    `year_level`                    VARCHAR(20)         NULL        DEFAULT NULL,
    `block`                         VARCHAR(50)         NULL        DEFAULT NULL,
    `agreed_to_terms`               TINYINT(1)          NOT NULL    DEFAULT 0,
    `trust_score`                   INT                 NOT NULL    DEFAULT 100,
    `remember_token`                VARCHAR(100)        NULL        DEFAULT NULL,
    `created_at`                    TIMESTAMP           NULL        DEFAULT NULL,
    `updated_at`                    TIMESTAMP           NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  password_reset_tokens
--  One-time password reset tokens keyed by e-mail address.
--  No surrogate key (email is the primary key per Laravel
--  convention).
-- ------------------------------------------------------------
CREATE TABLE `password_reset_tokens` (
    `email`         VARCHAR(255)    NOT NULL,
    `token`         VARCHAR(255)    NOT NULL,
    `created_at`    TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  sessions
--  Laravel database session driver store.
--  user_id is indexed but carries no FK constraint — a session
--  may outlive the user row during cleanup.
-- ------------------------------------------------------------
CREATE TABLE `sessions` (
    `id`            VARCHAR(255)    NOT NULL,
    `user_id`       BIGINT UNSIGNED NULL        DEFAULT NULL,
    `ip_address`    VARCHAR(45)     NULL        DEFAULT NULL,
    `user_agent`    TEXT            NULL,
    `payload`       LONGTEXT        NOT NULL,
    `last_activity` INT             NOT NULL,

    PRIMARY KEY (`id`),
    KEY `sessions_user_id_index`       (`user_id`),
    KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  remember_tokens
--  Persistent "remember me" tokens using the split-token
--  pattern (public selector + SHA-256 hashed verifier).
-- ------------------------------------------------------------
CREATE TABLE `remember_tokens` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `token_hash`        VARCHAR(255)    NOT NULL    COMMENT 'SHA-256 of the raw token; plaintext is never stored.',
    `selector`          VARCHAR(255)    NOT NULL    COMMENT 'Public cookie identifier used to locate the row.',
    `device_fingerprint` VARCHAR(255)   NULL        DEFAULT NULL,
    `device_name`       VARCHAR(255)    NULL        DEFAULT NULL,
    `ip_address`        VARCHAR(45)     NULL        DEFAULT NULL,
    `last_used_ip`      VARCHAR(45)     NULL        DEFAULT NULL,
    `expires_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `last_used_at`      TIMESTAMP       NULL        DEFAULT NULL,
    `is_revoked`        TINYINT(1)      NOT NULL    DEFAULT 0,
    `revoked_reason`    TEXT            NULL,
    `revoked_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `created_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`        TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `remember_tokens_selector_unique`   (`selector`),
    KEY        `remember_tokens_user_active_index` (`user_id`, `is_revoked`),
    CONSTRAINT `fk_remember_tokens_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  shortcut_keys
--  Quick-access PIN/shortcut credentials scoped to a device
--  fingerprint. Supports STAFF and SUPERADMIN types.
-- ------------------------------------------------------------
CREATE TABLE `shortcut_keys` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `shortcut_key_hash` VARCHAR(255)    NOT NULL,
    `device_fingerprint` VARCHAR(255)   NOT NULL,
    `shortcut_type`     ENUM('STAFF', 'SUPERADMIN') NOT NULL,
    `is_active`         TINYINT(1)      NOT NULL    DEFAULT 1,
    `last_used`         TIMESTAMP       NULL        DEFAULT NULL,
    `usage_count`       INT             NOT NULL    DEFAULT 0,
    `expires_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `created_by`        BIGINT UNSIGNED NOT NULL,
    `revoked_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `revoked_by`        BIGINT UNSIGNED NULL        DEFAULT NULL,
    `revoke_reason`     TEXT            NULL,
    `created_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`        TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `shortcut_keys_user_id_index` (`user_id`),
    CONSTRAINT `fk_shortcut_keys_user_id`
        FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shortcut_keys_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shortcut_keys_revoked_by`
        FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  security_settings
--  Global security configuration. Designed as a single-row
--  settings table keyed by `key_name` (e.g. 'global').
--  Intentionally has no created_at column.
-- ------------------------------------------------------------
CREATE TABLE `security_settings` (
    `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key_name`                  VARCHAR(255)    NOT NULL    COMMENT 'Singleton row key, e.g. ''global''.',
    `blocked_ips`               JSON            NULL,
    `require_2fa`               TINYINT(1)      NOT NULL    DEFAULT 0,
    `session_timeout_minutes`   INT             NOT NULL    DEFAULT 30,
    `updated_by`                VARCHAR(255)    NULL        DEFAULT NULL,
    `updated_at`                TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `security_settings_key_name_unique` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  personal_access_tokens
--  Laravel Sanctum API token table (polymorphic).
--  tokenable_type + tokenable_id form the morph pair.
-- ------------------------------------------------------------
CREATE TABLE `personal_access_tokens` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tokenable_type` VARCHAR(255)    NOT NULL,
    `tokenable_id`   BIGINT UNSIGNED NOT NULL,
    `name`           TEXT            NOT NULL,
    `token`          VARCHAR(64)     NOT NULL    COMMENT 'SHA-256 of the raw bearer token.',
    `abilities`      TEXT            NULL        COMMENT 'JSON array of granted abilities.',
    `last_used_at`   TIMESTAMP       NULL        DEFAULT NULL,
    `expires_at`     TIMESTAMP       NULL        DEFAULT NULL,
    `created_at`     TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`     TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
    KEY `personal_access_tokens_tokenable_type_tokenable_id_index`
        (`tokenable_type`, `tokenable_id`),
    KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  failed_login_attempts
--  Brute-force audit log. No FK to users because the email
--  may not match any existing account.
-- ------------------------------------------------------------
CREATE TABLE `failed_login_attempts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip`          VARCHAR(45)     NOT NULL,
    `email`       VARCHAR(255)    NOT NULL,
    `reason`      VARCHAR(255)    NOT NULL,
    `risk`        VARCHAR(50)     NOT NULL    COMMENT 'E.g. low | medium | high',
    `occurred_at` TIMESTAMP       NOT NULL    DEFAULT CURRENT_TIMESTAMP,
    `user_agent`  VARCHAR(500)    NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `failed_login_attempts_ip_index`          (`ip`),
    KEY `failed_login_attempts_email_index`       (`email`),
    KEY `failed_login_attempts_occurred_at_index` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 2 — INVENTORY
-- ============================================================

-- ------------------------------------------------------------
--  inventory_categories
--  Logical grouping for inventory items.
--  item_count is a denormalized counter kept in sync by the
--  application layer (InventoryController).
-- ------------------------------------------------------------
CREATE TABLE `inventory_categories` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(255)    NOT NULL,
    `description` TEXT            NULL,
    `picture`     VARCHAR(1000)   NULL        DEFAULT NULL    COMMENT 'Cloudinary URL or storage path.',
    `item_count`  INT             NOT NULL    DEFAULT 0       COMMENT 'Denormalized; kept in sync by application logic.',
    `archived`    TINYINT(1)      NOT NULL    DEFAULT 0,
    `created_by`  BIGINT UNSIGNED NOT NULL,
    `updated_by`  BIGINT UNSIGNED NULL        DEFAULT NULL,
    `created_at`  TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`  TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `inventory_categories_name_index`     (`name`),
    KEY `inventory_categories_archived_index` (`archived`),
    CONSTRAINT `fk_inv_categories_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inv_categories_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  inventory_items
--  Core inventory record.
--  Stock formula: available = quantity + donations - released
--  `category` is denormalized for query performance.
--  Soft-deleted rows are preserved in deleted_inventory_items.
-- ------------------------------------------------------------
CREATE TABLE `inventory_items` (
    `id`                        BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`                      VARCHAR(255)        NOT NULL,
    `category`                  VARCHAR(255)        NOT NULL    COMMENT 'Denormalized category name for query performance.',
    `category_id`               BIGINT UNSIGNED     NULL        DEFAULT NULL,
    `specification`             TEXT                NULL,
    `tools_or_equipment`        VARCHAR(50)         NOT NULL,
    `picture`                   VARCHAR(1000)       NULL        DEFAULT NULL    COMMENT 'Cloudinary URL or storage path.',
    `quantity`                  INT                 NOT NULL    DEFAULT 0       COMMENT 'Stock held in storage.',
    `donations`                 INT                 NOT NULL    DEFAULT 0       COMMENT 'Donated stock tracked separately.',
    `eom_count`                 INT                 NOT NULL    DEFAULT 0       COMMENT 'End-of-month physical count baseline.',
    `description`               TEXT                NULL,
    `status`                    ENUM(
                                    'In Stock',
                                    'Low Stock',
                                    'Out of Stock',
                                    'Archived'
                                )                   NOT NULL    DEFAULT 'In Stock',
    `unit_price`                DECIMAL(10, 2)      NULL        DEFAULT NULL,
    `is_required`               TINYINT(1)          NOT NULL    DEFAULT 0       COMMENT 'Flags items required in the standard subject kit.',
    `max_quantity_per_request`  INT                 NULL        DEFAULT NULL    COMMENT 'Per-request borrow cap. NULL = unlimited.',
    `archived`                  TINYINT(1)          NOT NULL    DEFAULT 0,
    `created_by`                BIGINT UNSIGNED     NOT NULL,
    `updated_by`                BIGINT UNSIGNED     NULL        DEFAULT NULL,
    `deleted_at`                TIMESTAMP           NULL        DEFAULT NULL    COMMENT 'Soft-delete sentinel (Laravel SoftDeletes).',
    `created_at`                TIMESTAMP           NULL        DEFAULT NULL,
    `updated_at`                TIMESTAMP           NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `inventory_items_name_index`        (`name`),
    KEY `inventory_items_category_id_index` (`category_id`),
    KEY `inventory_items_archived_index`    (`archived`),
    KEY `inventory_items_status_index`      (`status`),
    KEY `inventory_items_deleted_at_index`  (`deleted_at`),
    CONSTRAINT `fk_inv_items_category_id`
        FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_inv_items_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inv_items_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 3 — CLASS CODES & ENROLLMENT
-- ============================================================

-- ------------------------------------------------------------
--  class_codes
--  Represents a single class section for a given semester.
--  Students join via the human-readable `code`; instructors
--  are assigned through the junction table.
-- ------------------------------------------------------------
CREATE TABLE `class_codes` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`           VARCHAR(50)     NOT NULL    COMMENT 'Human-readable join code distributed to students.',
    `course_code`    VARCHAR(50)     NOT NULL,
    `course_name`    VARCHAR(255)    NOT NULL,
    `section`        VARCHAR(50)     NOT NULL,
    `academic_year`  VARCHAR(20)     NOT NULL    COMMENT 'E.g. 2025-2026',
    `semester`       ENUM('First', 'Second', 'Summer') NOT NULL,
    `max_enrollment` INT             NOT NULL,
    `is_active`      TINYINT(1)      NOT NULL    DEFAULT 1,
    `is_archived`    TINYINT(1)      NOT NULL    DEFAULT 0,
    `created_at`     TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`     TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `class_codes_code_unique` (`code`),
    KEY        `class_codes_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  class_code_instructor
--  Many-to-many: a class may have multiple instructors;
--  an instructor may manage multiple classes.
-- ------------------------------------------------------------
CREATE TABLE `class_code_instructor` (
    `class_code_id` BIGINT UNSIGNED NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (`class_code_id`, `user_id`),
    CONSTRAINT `fk_cci_class_code_id`
        FOREIGN KEY (`class_code_id`) REFERENCES `class_codes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cci_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  class_code_student
--  Many-to-many: student enrollment junction.
-- ------------------------------------------------------------
CREATE TABLE `class_code_student` (
    `class_code_id` BIGINT UNSIGNED NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (`class_code_id`, `user_id`),
    CONSTRAINT `fk_ccs_class_code_id`
        FOREIGN KEY (`class_code_id`) REFERENCES `class_codes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ccs_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 4 — BORROW REQUESTS
-- ============================================================

-- ------------------------------------------------------------
--  borrow_requests
--  Central transaction record for an equipment borrow event.
--  Progresses through a status state machine; every transition
--  is recorded via a dedicated lifecycle timestamp column.
-- ------------------------------------------------------------
CREATE TABLE `borrow_requests` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`       BIGINT UNSIGNED NOT NULL,
    `instructor_id`    BIGINT UNSIGNED NULL        DEFAULT NULL,
    `custodian_id`     BIGINT UNSIGNED NULL        DEFAULT NULL,
    `class_code_id`    BIGINT UNSIGNED NOT NULL,
    `purpose`          TEXT            NOT NULL,
    `usage_location`   ENUM('school', 'outdoor') NULL DEFAULT NULL,
    `borrow_date`      TIMESTAMP       NULL        DEFAULT NULL,
    `return_date`      TIMESTAMP       NULL        DEFAULT NULL,
    `status`           ENUM(
                           'pending_instructor',
                           'approved_instructor',
                           'ready_for_pickup',
                           'borrowed',
                           'pending_return',
                           'missing',
                           'resolved',
                           'returned',
                           'cancelled',
                           'rejected',
                           'pending_appeal'
                       )               NOT NULL    DEFAULT 'pending_instructor',

    -- Rejection & Appeal
    `reject_reason`    TEXT            NULL,
    `rejection_notes`  TEXT            NULL,
    `appeal_reason`    TEXT            NULL,
    `appealed_at`      TIMESTAMP       NULL        DEFAULT NULL,
    `appeal_count`     INT             NOT NULL    DEFAULT 0,

    -- Lifecycle timestamps (set exactly once per transition)
    `approved_at`      TIMESTAMP       NULL        DEFAULT NULL,
    `rejected_at`      TIMESTAMP       NULL        DEFAULT NULL,
    `released_at`      TIMESTAMP       NULL        DEFAULT NULL,
    `picked_up_at`     TIMESTAMP       NULL        DEFAULT NULL,
    `missing_at`       TIMESTAMP       NULL        DEFAULT NULL,
    `resolved_at`      TIMESTAMP       NULL        DEFAULT NULL,
    `returned_at`      TIMESTAMP       NULL        DEFAULT NULL,

    -- Overdue reminders
    `last_reminder_at` TIMESTAMP       NULL        DEFAULT NULL,
    `reminder_count`   INT             NOT NULL    DEFAULT 0,

    -- Audit
    `created_by`       BIGINT UNSIGNED NOT NULL,
    `updated_by`       BIGINT UNSIGNED NULL        DEFAULT NULL,
    `created_at`       TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`       TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `borrow_requests_student_id_index`    (`student_id`),
    KEY `borrow_requests_instructor_id_index` (`instructor_id`),
    KEY `borrow_requests_custodian_id_index`  (`custodian_id`),
    KEY `borrow_requests_class_code_id_index` (`class_code_id`),
    KEY `borrow_requests_status_index`        (`status`),
    CONSTRAINT `fk_br_student_id`
        FOREIGN KEY (`student_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_br_instructor_id`
        FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_br_custodian_id`
        FOREIGN KEY (`custodian_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_br_class_code_id`
        FOREIGN KEY (`class_code_id`) REFERENCES `class_codes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_br_created_by`
        FOREIGN KEY (`created_by`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_br_updated_by`
        FOREIGN KEY (`updated_by`)    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  borrow_request_items
--  Line-item detail for a borrow request.
--  name / category / picture are snapshots taken at request
--  time so that history is preserved even if the source
--  inventory item is later modified, archived, or deleted.
-- ------------------------------------------------------------
CREATE TABLE `borrow_request_items` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `borrow_request_id`    BIGINT UNSIGNED NOT NULL,
    `item_id`              BIGINT UNSIGNED NOT NULL,
    `name`                 VARCHAR(255)    NOT NULL    COMMENT 'Snapshot of item name at request time.',
    `quantity`             INT             NOT NULL,
    `category`             VARCHAR(255)    NULL        DEFAULT NULL    COMMENT 'Snapshot of category name at request time.',
    `picture`              VARCHAR(1000)   NULL        DEFAULT NULL    COMMENT 'Snapshot of picture URL at request time.',

    -- Return inspection
    `inspection_status`    ENUM('good', 'damaged', 'missing') NULL DEFAULT NULL,
    `inspection_date`      TIMESTAMP       NULL        DEFAULT NULL,
    `inspected_by`         BIGINT UNSIGNED NULL        DEFAULT NULL,
    `inspection_notes`     TEXT            NULL,
    `replacement_quantity` INT             NULL        DEFAULT NULL    COMMENT 'Quantity flagged for a replacement obligation.',
    `due_date`             TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `borrow_request_items_request_id_index` (`borrow_request_id`),
    KEY `borrow_request_items_item_id_index`    (`item_id`),
    CONSTRAINT `fk_bri_borrow_request_id`
        FOREIGN KEY (`borrow_request_id`) REFERENCES `borrow_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bri_item_id`
        FOREIGN KEY (`item_id`)           REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bri_inspected_by`
        FOREIGN KEY (`inspected_by`)      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 5 — DONATIONS & REPLACEMENT OBLIGATIONS
-- ============================================================

-- ------------------------------------------------------------
--  donations
--  Records physical item donations. inventory_action drives
--  whether the items are added as a new inventory record or
--  merged into an existing one.
-- ------------------------------------------------------------
CREATE TABLE `donations` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_number`    VARCHAR(100)    NOT NULL,
    `donor_name`        VARCHAR(255)    NOT NULL,
    `item_name`         VARCHAR(255)    NOT NULL,
    `quantity`          INT             NOT NULL,
    `unit`              VARCHAR(50)     NULL        DEFAULT NULL,
    `purpose`           TEXT            NOT NULL,
    `date`              TIMESTAMP       NULL        DEFAULT NULL,
    `notes`             TEXT            NULL,
    `inventory_action`  ENUM('new_item', 'add_to_existing') NOT NULL,
    `inventory_item_id` BIGINT UNSIGNED NULL        DEFAULT NULL,
    `created_by`        BIGINT UNSIGNED NOT NULL,
    `created_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`        TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `donations_receipt_number_unique` (`receipt_number`),
    KEY `donations_inventory_item_id_index` (`inventory_item_id`),
    CONSTRAINT `fk_donations_inventory_item_id`
        FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_donations_created_by`
        FOREIGN KEY (`created_by`)        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  replacement_obligations
--  Financial or physical replacement liability created when
--  a returned item is inspected as 'damaged' or 'missing'.
--  item_name / item_category are snapshots for audit integrity.
-- ------------------------------------------------------------
CREATE TABLE `replacement_obligations` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `borrow_request_id` BIGINT UNSIGNED NOT NULL,
    `student_id`        BIGINT UNSIGNED NOT NULL,
    `item_id`           BIGINT UNSIGNED NOT NULL,
    `item_name`         VARCHAR(255)    NOT NULL    COMMENT 'Snapshot of item name.',
    `item_category`     VARCHAR(255)    NULL        DEFAULT NULL    COMMENT 'Snapshot of category name.',
    `quantity`          INT             NOT NULL,
    `type`              ENUM('missing', 'damaged') NOT NULL,
    `status`            ENUM('pending', 'replaced') NOT NULL DEFAULT 'pending',
    `amount`            INT             NOT NULL    COMMENT 'Assessed replacement quantity in base unit.',
    `amount_paid`       INT             NOT NULL    DEFAULT 0   COMMENT 'Quantity already replaced.',
    `resolution_type`   ENUM('replacement') NULL   DEFAULT NULL,
    `resolution_date`   TIMESTAMP       NULL        DEFAULT NULL,
    `resolution_notes`  TEXT            NULL,
    `payment_reference` VARCHAR(255)    NULL        DEFAULT NULL    COMMENT 'Tracking / reference number.',
    `incident_date`     TIMESTAMP       NOT NULL,
    `incident_notes`    TEXT            NULL,
    `due_date`          TIMESTAMP       NULL        DEFAULT NULL,
    `created_by`        BIGINT UNSIGNED NOT NULL,
    `updated_by`        BIGINT UNSIGNED NULL        DEFAULT NULL,
    `created_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`        TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `replacement_obligations_student_id_index`  (`student_id`),
    KEY `replacement_obligations_request_id_index`  (`borrow_request_id`),
    KEY `replacement_obligations_status_index`      (`status`),
    CONSTRAINT `fk_ro_borrow_request_id`
        FOREIGN KEY (`borrow_request_id`) REFERENCES `borrow_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ro_student_id`
        FOREIGN KEY (`student_id`)        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ro_item_id`
        FOREIGN KEY (`item_id`)           REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ro_created_by`
        FOREIGN KEY (`created_by`)        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ro_updated_by`
        FOREIGN KEY (`updated_by`)        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 6 — NOTIFICATIONS
-- ============================================================

-- ------------------------------------------------------------
--  notifications
--  In-app notification inbox per user. Linked to the
--  triggering borrow request where applicable.
-- ------------------------------------------------------------
CREATE TABLE `notifications` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `audience_role`     ENUM(
                            'student',
                            'instructor',
                            'custodian',
                            'admin',
                            'superadmin'
                        )               NOT NULL,
    `type`              VARCHAR(100)    NOT NULL    COMMENT 'Application-defined event type, e.g. borrow_approved.',
    `title`             VARCHAR(255)    NOT NULL,
    `message`           TEXT            NOT NULL,
    `link`              VARCHAR(1000)   NULL        DEFAULT NULL    COMMENT 'Deep link for in-app navigation.',
    `borrow_request_id` BIGINT UNSIGNED NULL        DEFAULT NULL,
    `metadata`          JSON            NULL        COMMENT 'Arbitrary additional context.',
    `is_read`           TINYINT(1)      NOT NULL    DEFAULT 0,
    `read_at`           TIMESTAMP       NULL        DEFAULT NULL,
    `created_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`        TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `notifications_user_is_read_index`       (`user_id`, `is_read`),
    KEY `notifications_borrow_request_id_index`  (`borrow_request_id`),
    KEY `notifications_created_at_index`         (`created_at`),
    CONSTRAINT `fk_notifications_user_id`
        FOREIGN KEY (`user_id`)           REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_borrow_request_id`
        FOREIGN KEY (`borrow_request_id`) REFERENCES `borrow_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 7 — SUPPORT TICKETS
-- ============================================================

-- ------------------------------------------------------------
--  support_tickets
--  Help desk conversation thread. unread_by_* counters are
--  denormalized for O(1) badge rendering.
-- ------------------------------------------------------------
CREATE TABLE `support_tickets` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`            BIGINT UNSIGNED NOT NULL,
    `owner_role`            ENUM('student', 'instructor', 'custodian') NOT NULL,
    `subject`               VARCHAR(255)    NOT NULL,
    `status`                ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    `last_message_at`       TIMESTAMP       NULL        DEFAULT NULL,
    `unread_by_superadmin`  INT             NOT NULL    DEFAULT 0,
    `unread_by_student`     INT             NOT NULL    DEFAULT 0,
    `created_at`            TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`            TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `support_tickets_student_id_index` (`student_id`),
    KEY `support_tickets_status_index`     (`status`),
    CONSTRAINT `fk_st_student_id`
        FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  support_ticket_messages
--  Individual messages within a support ticket thread.
--  sender_name is a snapshot of the display name at send time.
-- ------------------------------------------------------------
CREATE TABLE `support_ticket_messages` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `support_ticket_id` BIGINT UNSIGNED NOT NULL,
    `sender`            ENUM('student', 'instructor', 'custodian', 'superadmin') NOT NULL,
    `sender_id`         BIGINT UNSIGNED NOT NULL,
    `sender_name`       VARCHAR(255)    NOT NULL    COMMENT 'Snapshot of display name at send time.',
    `body`              TEXT            NOT NULL,
    `sent_at`           TIMESTAMP       NULL        DEFAULT NULL,
    `created_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `updated_at`        TIMESTAMP       NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `support_ticket_messages_ticket_id_index` (`support_ticket_id`),
    KEY `support_ticket_messages_sender_id_index` (`sender_id`),
    CONSTRAINT `fk_stm_support_ticket_id`
        FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_stm_sender_id`
        FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 8 — STUDENT CART
-- ============================================================

-- ------------------------------------------------------------
--  student_carts
--  Ephemeral borrow cart. One row per (student × item) pair.
--  item_id is VARCHAR to survive potential UUID migration on
--  inventory_items. Cart rows are purged after a borrow
--  request is submitted.
-- ------------------------------------------------------------
CREATE TABLE `student_carts` (
    `id`           BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED     NOT NULL,
    `item_id`      VARCHAR(255)        NOT NULL    COMMENT 'Stored as VARCHAR to decouple from inventory_items PK type.',
    `name`         VARCHAR(255)        NOT NULL    COMMENT 'Snapshot of item name.',
    `quantity`     INT UNSIGNED        NOT NULL    DEFAULT 1,
    `max_quantity` INT UNSIGNED        NOT NULL,
    `category_id`  VARCHAR(255)        NULL        DEFAULT NULL,
    `picture`      VARCHAR(1000)       NULL        DEFAULT NULL,
    `added_at`     DATETIME            NOT NULL,
    `updated_at`   DATETIME            NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `student_carts_user_item_unique` (`user_id`, `item_id`),
    CONSTRAINT `fk_sc_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 9 — AUDIT & LOGS
-- ============================================================

-- ------------------------------------------------------------
--  inventory_history
--  Append-only audit trail for all inventory CRUD operations.
--  entity_name / user_name / user_role are snapshots, making
--  the log self-contained after source records change.
-- ------------------------------------------------------------
CREATE TABLE `inventory_history` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`      VARCHAR(100)    NOT NULL    COMMENT 'E.g. item_created | item_updated | item_deleted | category_created …',
    `entity_type` ENUM('item', 'category') NOT NULL,
    `entity_id`   BIGINT UNSIGNED NOT NULL,
    `entity_name` VARCHAR(255)    NOT NULL    COMMENT 'Snapshot of entity name at action time.',
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `user_name`   VARCHAR(255)    NOT NULL    COMMENT 'Snapshot of actor display name.',
    `user_role`   VARCHAR(20)     NOT NULL    COMMENT 'Snapshot of actor role.',
    `changes`     JSON            NULL        COMMENT 'Array of {field, oldValue, newValue} diff objects.',
    `metadata`    JSON            NULL        COMMENT 'Freeform context bag (e.g. reason, adjustment type).',
    `ip_address`  VARCHAR(45)     NULL        DEFAULT NULL,
    `user_agent`  VARCHAR(500)    NULL        DEFAULT NULL,
    `timestamp`   TIMESTAMP       NOT NULL    DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `inventory_history_entity_index`    (`entity_type`, `entity_id`),
    KEY `inventory_history_user_id_index`   (`user_id`),
    KEY `inventory_history_timestamp_index` (`timestamp`),
    KEY `inventory_history_action_index`    (`action`),
    CONSTRAINT `fk_ih_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  deleted_inventory_items
--  Soft-delete archive for inventory items.
--  item_data holds a full JSON snapshot of the deleted row.
--  Rows are permanently purged by a scheduled job after
--  scheduled_deletion (typically deleted_at + 30 days).
-- ------------------------------------------------------------
CREATE TABLE `deleted_inventory_items` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_id`       BIGINT UNSIGNED NOT NULL    COMMENT 'inventory_items.id at deletion; no FK (source row is gone).',
    `item_data`         JSON            NOT NULL    COMMENT 'Full serialized snapshot of the deleted row.',
    `deleted_by`        BIGINT UNSIGNED NOT NULL,
    `deleted_by_name`   VARCHAR(255)    NOT NULL,
    `deleted_by_role`   VARCHAR(20)     NOT NULL,
    `deleted_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `scheduled_deletion` TIMESTAMP      NULL        DEFAULT NULL    COMMENT 'Hard-delete deadline (deleted_at + 30 days).',
    `reason`            TEXT            NULL,
    `ip_address`        VARCHAR(45)     NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `deleted_inventory_items_original_id_index`   (`original_id`),
    KEY `deleted_inventory_items_scheduled_del_index` (`scheduled_deletion`),
    CONSTRAINT `fk_dii_deleted_by`
        FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  deleted_inventory_categories
--  Soft-delete archive for inventory categories.
--  Mirrors deleted_inventory_items in structure.
-- ------------------------------------------------------------
CREATE TABLE `deleted_inventory_categories` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_id`       BIGINT UNSIGNED NOT NULL    COMMENT 'inventory_categories.id at deletion; no FK.',
    `category_data`     JSON            NOT NULL    COMMENT 'Full serialized snapshot of the deleted row.',
    `deleted_by`        BIGINT UNSIGNED NOT NULL,
    `deleted_by_name`   VARCHAR(255)    NOT NULL,
    `deleted_by_role`   VARCHAR(20)     NOT NULL,
    `deleted_at`        TIMESTAMP       NULL        DEFAULT NULL,
    `scheduled_deletion` TIMESTAMP      NULL        DEFAULT NULL    COMMENT 'Hard-delete deadline (deleted_at + 30 days).',
    `reason`            TEXT            NULL,
    `ip_address`        VARCHAR(45)     NULL        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `deleted_inv_categories_original_id_index`   (`original_id`),
    KEY `deleted_inv_categories_scheduled_del_index` (`scheduled_deletion`),
    CONSTRAINT `fk_dic_deleted_by`
        FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  SECTION 10 — FRAMEWORK / QUEUE INFRASTRUCTURE
-- ============================================================

-- ------------------------------------------------------------
--  cache / cache_locks
--  Laravel database cache driver store and atomic lock store.
-- ------------------------------------------------------------
CREATE TABLE `cache` (
    `key`        VARCHAR(255) NOT NULL,
    `value`      MEDIUMTEXT   NOT NULL,
    `expiration` INT          NOT NULL,

    PRIMARY KEY (`key`),
    KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
    `key`        VARCHAR(255) NOT NULL,
    `owner`      VARCHAR(255) NOT NULL,
    `expiration` INT          NOT NULL,

    PRIMARY KEY (`key`),
    KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  jobs / job_batches / failed_jobs
--  Laravel queue infrastructure tables.
-- ------------------------------------------------------------
CREATE TABLE `jobs` (
    `id`           BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `queue`        VARCHAR(255)        NOT NULL,
    `payload`      LONGTEXT            NOT NULL,
    `attempts`     TINYINT UNSIGNED    NOT NULL,
    `reserved_at`  INT UNSIGNED        NULL        DEFAULT NULL,
    `available_at` INT UNSIGNED        NOT NULL,
    `created_at`   INT UNSIGNED        NOT NULL,

    PRIMARY KEY (`id`),
    KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
    `id`             VARCHAR(255) NOT NULL,
    `name`           VARCHAR(255) NOT NULL,
    `total_jobs`     INT          NOT NULL,
    `pending_jobs`   INT          NOT NULL,
    `failed_jobs`    INT          NOT NULL,
    `failed_job_ids` LONGTEXT     NOT NULL,
    `options`        MEDIUMTEXT   NULL,
    `cancelled_at`   INT          NULL        DEFAULT NULL,
    `created_at`     INT          NOT NULL,
    `finished_at`    INT          NULL        DEFAULT NULL,

    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`       VARCHAR(255)    NOT NULL,
    `connection` TEXT            NOT NULL,
    `queue`      TEXT            NOT NULL,
    `payload`    LONGTEXT        NOT NULL,
    `exception`  LONGTEXT        NOT NULL,
    `failed_at`  TIMESTAMP       NOT NULL    DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;
-- ============================================================
