-- Mass Open — database schema.
--
-- Apply with:  mysql -u <user> -p <database> < schema.sql
--
-- RETIRED: the newsletter double opt-in flow. Subscriptions now live in the
-- self-hosted Ghost site at news.massopen.ai, and submit.php / confirm.php /
-- unsubscribe.php have been removed. The `subscribers` and `signup_throttle`
-- tables are kept, NOT dropped, for two reasons:
--
--   1. cfp_submit.php still reads `subscribers` to let an address that already
--      confirmed skip proposal verification. Dropping it breaks the CFP.
--   2. It is the record of who consented and when — worth keeping even though
--      nothing writes to it any more.
--
-- Nothing writes to either table now. Once the list is in Ghost (see
-- tools/migrate-subscribers-to-ghost.sh) and you no longer want the CFP
-- shortcut, they can go:
--
--   -- DROP TABLE signup_throttle;
--   -- DROP TABLE subscribers;   -- also remove the lookup in cfp_submit.php
--
-- A row is created at signup with status='pending'. It only becomes
-- 'confirmed' after the recipient clicks the link in the confirmation email
-- AND submits the confirmation form, proving control of the mailbox.
-- Only rows with status='confirmed' should ever receive mail.

CREATE TABLE IF NOT EXISTS subscribers (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Address as typed (for display / sending) and lowercased (for uniqueness).
  email                  VARCHAR(254) NOT NULL,
  email_canonical        VARCHAR(254) NOT NULL,

  status                 ENUM('pending','confirmed','unsubscribed')
                           NOT NULL DEFAULT 'pending',

  -- SHA-256 hex of the confirmation token. The raw token only ever exists in
  -- the email, so a database leak cannot be used to confirm addresses.
  confirm_token_hash     CHAR(64)     DEFAULT NULL,
  confirm_expires_at     DATETIME     DEFAULT NULL,
  confirm_sent_at        DATETIME     DEFAULT NULL,
  confirm_send_count     INT UNSIGNED NOT NULL DEFAULT 0,

  -- The unsubscribe token is stored in the clear, unlike the confirm token.
  -- It has to be reproducible to build the unsubscribe link in every future
  -- newsletter, and a hash cannot be reversed to do that. It is a low-value
  -- capability: the worst a leak allows is unsubscribing someone, which is
  -- reversible and grants no access to anything.
  unsubscribe_token      CHAR(64)     DEFAULT NULL,

  confirmed_at           DATETIME     DEFAULT NULL,
  unsubscribed_at        DATETIME     DEFAULT NULL,

  -- Consent evidence: who asked, from where, and when. Keep this — it is what
  -- demonstrates opt-in if a complaint or audit ever arrives.
  signup_ip              VARCHAR(45)  DEFAULT NULL,
  signup_user_agent      VARCHAR(255) DEFAULT NULL,
  confirm_ip             VARCHAR(45)  DEFAULT NULL,
  confirm_user_agent     VARCHAR(255) DEFAULT NULL,

  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_email_canonical (email_canonical),
  KEY idx_confirm_token (confirm_token_hash),
  KEY idx_unsubscribe_token (unsubscribe_token),
  KEY idx_status (status)
) ENGINE=InnoDB
  ROW_FORMAT=DYNAMIC
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- Per-IP signup throttle, so the form cannot be used to blast confirmation
-- mail at third parties (a "list bombing" attack, which harms your sending
-- reputation as much as the victim).
CREATE TABLE IF NOT EXISTS signup_throttle (
  ip                VARCHAR(45)  NOT NULL,
  attempts          INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME     NOT NULL,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ip)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Call for papers
-- ---------------------------------------------------------------------------

-- One row per talk proposal. A person may submit more than one, so the email
-- is deliberately NOT unique here.
--
-- A proposal counts as real once `status` = 'verified'. That happens either
-- because the submitter clicked a verification link (unknown address) or
-- because they were already a confirmed subscriber, which is itself proof of
-- a working mailbox — see `verified_via`.
CREATE TABLE IF NOT EXISTS cfp_submissions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  name               VARCHAR(120) NOT NULL,
  email              VARCHAR(254) NOT NULL,
  email_canonical    VARCHAR(254) NOT NULL,
  bio                TEXT         NOT NULL,
  topic              VARCHAR(200) NOT NULL,
  abstract           TEXT         NOT NULL,

  -- Where the speaker's headshot lives: a path under the site
  -- ('/assets/img/speakers/reva-schwartz.jpg') or a full https:// URL. Set by
  -- an organiser in the review console, never by the submitter — see
  -- cfp_admin.php, which is the only thing that validates it. NULL means the
  -- agenda falls back to a file named after the speaker, then to their
  -- initials, so a missing photo is never a broken image.
  headshot           VARCHAR(255) DEFAULT NULL,

  -- `status` is the SUBMITTER's axis: did they verify their address?
  -- cfp_confirm.php matches on status='pending', so review states must never
  -- be added to this enum or triaging a proposal would break verification.
  status             ENUM('pending','verified','withdrawn')
                       NOT NULL DEFAULT 'pending',
  verified_via       ENUM('email','known_subscriber') DEFAULT NULL,

  -- `review_status` is the ORGANISER's axis, entirely independent of the above.
  review_status      ENUM('new','shortlist','accepted','rejected')
                       NOT NULL DEFAULT 'new',
  reviewer_notes     TEXT         DEFAULT NULL,
  reviewed_at        DATETIME     DEFAULT NULL,

  verify_token_hash  CHAR(64)     DEFAULT NULL,
  verify_expires_at  DATETIME     DEFAULT NULL,
  verify_sent_at     DATETIME     DEFAULT NULL,
  verified_at        DATETIME     DEFAULT NULL,

  -- Anti-spam telemetry, kept so you can tune the thresholds against real
  -- traffic rather than guesses.
  -- Scheduling: set in the review console once a talk is accepted.
  event_id           BIGINT UNSIGNED DEFAULT NULL,
  slot_order         INT          NOT NULL DEFAULT 0,

  spam_score         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fill_seconds       INT UNSIGNED DEFAULT NULL,

  submit_ip          VARCHAR(45)  DEFAULT NULL,
  submit_user_agent  VARCHAR(255) DEFAULT NULL,

  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_cfp_verify_token (verify_token_hash),
  KEY idx_cfp_email (email_canonical),
  KEY idx_cfp_status (status),
  KEY idx_cfp_review_status (review_status),
  KEY idx_cfp_event (event_id, slot_order)
) ENGINE=InnoDB
  ROW_FORMAT=DYNAMIC
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- Every admin edit to a proposal, one row per changed field.
--
-- These are words someone else wrote and submitted. When a speaker says "that
-- is not what I sent you", this is the answer — and it is the undo for a
-- mistaken overwrite.
CREATE TABLE IF NOT EXISTS cfp_revisions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id  BIGINT UNSIGNED NOT NULL,
  field          VARCHAR(32)  NOT NULL,
  old_value      TEXT         DEFAULT NULL,
  new_value      TEXT         DEFAULT NULL,
  edited_by      VARCHAR(64)  DEFAULT NULL,   -- the authenticated HTTP user
  edited_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rev_submission (submission_id, edited_at),
  CONSTRAINT fk_rev_submission FOREIGN KEY (submission_id)
    REFERENCES cfp_submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB
  ROW_FORMAT=DYNAMIC
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- Upgrading an existing install (MariaDB supports IF NOT EXISTS here, so this
-- is safe to re-run):
--
   ALTER TABLE cfp_submissions
     ADD COLUMN IF NOT EXISTS review_status
       ENUM('new','shortlist','accepted','rejected') NOT NULL DEFAULT 'new',
     ADD COLUMN IF NOT EXISTS reviewer_notes TEXT DEFAULT NULL,
     ADD COLUMN IF NOT EXISTS reviewed_at DATETIME DEFAULT NULL,
     ADD KEY IF NOT EXISTS idx_cfp_review_status (review_status);

-- The speaker headshot shown next to the bio on a talk page:
--
   ALTER TABLE cfp_submissions
     ADD COLUMN IF NOT EXISTS headshot VARCHAR(255) DEFAULT NULL;


-- ---------------------------------------------------------------------------
-- Events and the published agenda
-- ---------------------------------------------------------------------------

-- Events are authored in _data/events.yml and synced into this table by
-- tools/agenda.php sync. Git stays the source of truth for the wording; this
-- table exists so a talk can point at an event, and so the admin can offer a
-- real dropdown instead of a free-text field.
CREATE TABLE IF NOT EXISTS events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(64)  NOT NULL,
  title      VARCHAR(200) NOT NULL,
  starts_on  DATE         DEFAULT NULL,
  location   VARCHAR(160) DEFAULT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event_slug (slug),
  KEY idx_event_date (starts_on)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scheduling lives on the submission: which event it is on, and where in the
-- running order. ON DELETE SET NULL so removing an event unschedules its talks
-- rather than deleting proposals.
--
--#   ALTER TABLE cfp_submissions
--#     ADD COLUMN IF NOT EXISTS event_id BIGINT UNSIGNED DEFAULT NULL,
--#     ADD COLUMN IF NOT EXISTS slot_order INT NOT NULL DEFAULT 0,
--#     ADD KEY IF NOT EXISTS idx_cfp_event (event_id, slot_order),
--#     ADD CONSTRAINT fk_cfp_event FOREIGN KEY (event_id)
--#       REFERENCES events (id) ON DELETE SET NULL;


-- Single-use form nonces, issued by cfp_token.php when the form is rendered.
--
-- This is the timing trap and the invisible challenge in one. Because the
-- issue time lives here rather than in a hidden field, the client cannot lie
-- about how long the form took to fill in, and marking the row used makes a
-- harvested token worthless for a second submission.
CREATE TABLE IF NOT EXISTS form_nonces (
  nonce      CHAR(64)    NOT NULL,
  issued_at  DATETIME    NOT NULL,
  issued_ip  VARCHAR(45) DEFAULT NULL,
  used_at    DATETIME    DEFAULT NULL,
  PRIMARY KEY (nonce),
  KEY idx_nonce_issued (issued_at),
  KEY idx_nonce_ip (issued_ip, issued_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Housekeeping — run from cron occasionally:
--   DELETE FROM form_nonces WHERE issued_at < NOW() - INTERVAL 2 DAY;


-- ---------------------------------------------------------------------------
-- Migrating from the previous single opt-in table
-- ---------------------------------------------------------------------------
-- The old table stored (email, ip_address) and treated every row as
-- subscribed. Those addresses never confirmed, so they are NOT double
-- opt-in. Import them as 'pending' and send a one-time confirmation, or
-- leave them alone — do not mark them 'confirmed'.
--
--   INSERT IGNORE INTO subscribers (email, email_canonical, status, signup_ip)
--   SELECT email, LOWER(email), 'pending', ip_address FROM old_subscribers;
--
-- Useful queries:
--   SELECT COUNT(*) FROM subscribers WHERE status='confirmed';
--   DELETE FROM subscribers
--     WHERE status='pending' AND confirm_expires_at < NOW() - INTERVAL 30 DAY;
