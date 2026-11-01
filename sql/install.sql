-- somcp_oauth_client - one row per MCP client known to this shop.
--
-- `origin` says how the row came to exist: `cimd` for a client identified by a
-- metadata document it hosts (the client_id is then the document URL), `dcr` for
-- one that registered itself, `static` for one entered by the merchant. A CIMD
-- row is a cache of a remote document, never the source of truth: it is refetched
-- once `cache_expires_at` has passed.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_oauth_client` (
    `id_client`         INT(11)      NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11)      NOT NULL DEFAULT 1,
    `client_id`         VARCHAR(255) NOT NULL,
    `origin`            VARCHAR(10)  NOT NULL DEFAULT 'dcr' COMMENT 'cimd, dcr or static',
    `client_name`       VARCHAR(255) NOT NULL DEFAULT '',
    `client_uri`        VARCHAR(255) NOT NULL DEFAULT '',
    `logo_uri`          VARCHAR(255) NOT NULL DEFAULT '',
    `redirect_uris`     TEXT         NOT NULL,
    `grant_types`       VARCHAR(255) NOT NULL DEFAULT 'authorization_code refresh_token',
    `token_auth_method` VARCHAR(30)  NOT NULL DEFAULT 'none',
    `client_secret`     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'sha256 of the secret, never the secret',
    `application_type`  VARCHAR(10)  NOT NULL DEFAULT 'native',
    `cache_expires_at`  DATETIME     DEFAULT NULL COMMENT 'CIMD documents only',
    `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
    `date_add`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_upd`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_client`),
    UNIQUE KEY `uq_client_shop` (`client_id`, `id_shop`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_oauth_request - an authorization request waiting for the merchant.
--
-- The front controller validates the request, stores it here, and sends the
-- browser to the back office carrying nothing but an opaque id. PrestaShop 8
-- drops the query string when it bounces a visitor through the login form
-- (AdminLoginController only carries a controller name), so the OAuth parameters
-- cannot travel that way and have to wait here instead.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_oauth_request` (
    `id_request`             INT(11)      NOT NULL AUTO_INCREMENT,
    `request_id`             VARCHAR(64)  NOT NULL COMMENT 'sha256 of the value held in the browser cookie',
    `id_shop`                INT(11)      NOT NULL DEFAULT 1,
    `id_client`              INT(11)      NOT NULL,
    `redirect_uri`           VARCHAR(500) NOT NULL,
    `scopes`                 VARCHAR(500) NOT NULL DEFAULT '',
    `state`                  VARCHAR(500) NOT NULL DEFAULT '',
    `code_challenge`         VARCHAR(128) NOT NULL,
    `code_challenge_method`  VARCHAR(10)  NOT NULL DEFAULT 'S256',
    `resource`               VARCHAR(500) NOT NULL DEFAULT '',
    `csrf_token`             VARCHAR(64)  NOT NULL COMMENT 'guards the consent form, not the OAuth request',
    `expires_at`             DATETIME     NOT NULL,
    `consumed_at`            DATETIME     DEFAULT NULL,
    `date_add`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_request`),
    UNIQUE KEY `uq_request_id` (`request_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_oauth_code - authorization codes. Single use: a second presentation
-- revokes every token descended from the first one (OAuth 2.1, code replay).
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_oauth_code` (
    `id_code`                INT(11)      NOT NULL AUTO_INCREMENT,
    `code_hash`              VARCHAR(64)  NOT NULL,
    `id_shop`                INT(11)      NOT NULL DEFAULT 1,
    `id_client`              INT(11)      NOT NULL,
    `id_employee`            INT(11)      NOT NULL,
    `redirect_uri`           VARCHAR(500) NOT NULL,
    `scopes`                 VARCHAR(500) NOT NULL DEFAULT '',
    `resource`               VARCHAR(500) NOT NULL DEFAULT '',
    `code_challenge`         VARCHAR(128) NOT NULL,
    `code_challenge_method`  VARCHAR(10)  NOT NULL DEFAULT 'S256',
    `id_family`              VARCHAR(64)  NOT NULL COMMENT 'ties the code to the tokens issued from it',
    `expires_at`             DATETIME     NOT NULL,
    `used_at`                DATETIME     DEFAULT NULL,
    `date_add`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_code`),
    UNIQUE KEY `uq_code_hash` (`code_hash`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_oauth_access_token - bearer tokens presented on the MCP endpoint.
-- `resource` is the audience the token was minted for and is compared with the
-- shop's own MCP endpoint on every call (RFC 8707).
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_oauth_access_token` (
    `id_access_token` INT(11)      NOT NULL AUTO_INCREMENT,
    `token_hash`      VARCHAR(64)  NOT NULL,
    `id_shop`         INT(11)      NOT NULL DEFAULT 1,
    `id_client`       INT(11)      NOT NULL,
    `id_employee`     INT(11)      NOT NULL,
    `scopes`          VARCHAR(500) NOT NULL DEFAULT '',
    `resource`        VARCHAR(500) NOT NULL DEFAULT '',
    `id_family`       VARCHAR(64)  NOT NULL,
    `expires_at`      DATETIME     NOT NULL,
    `revoked_at`      DATETIME     DEFAULT NULL,
    `last_used_at`    DATETIME     DEFAULT NULL,
    `date_add`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_access_token`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_family` (`id_family`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_oauth_refresh_token - rotated on every use. `rotated_to` keeps the chain
-- so that a token presented twice reveals a theft and takes the whole family down.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_oauth_refresh_token` (
    `id_refresh_token` INT(11)      NOT NULL AUTO_INCREMENT,
    `token_hash`       VARCHAR(64)  NOT NULL,
    `id_shop`          INT(11)      NOT NULL DEFAULT 1,
    `id_client`        INT(11)      NOT NULL,
    `id_employee`      INT(11)      NOT NULL,
    `scopes`           VARCHAR(500) NOT NULL DEFAULT '',
    `resource`         VARCHAR(500) NOT NULL DEFAULT '',
    `id_family`        VARCHAR(64)  NOT NULL,
    `rotated_to`       VARCHAR(64)  NOT NULL DEFAULT '',
    `expires_at`       DATETIME     NOT NULL,
    `used_at`          DATETIME     DEFAULT NULL,
    `revoked_at`       DATETIME     DEFAULT NULL,
    `date_add`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_refresh_token`),
    UNIQUE KEY `uq_refresh_hash` (`token_hash`),
    KEY `idx_family` (`id_family`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_oauth_consent - what an employee has already granted to a client, so a
-- returning assistant is not asked again for permissions already given.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_oauth_consent` (
    `id_consent`  INT(11)      NOT NULL AUTO_INCREMENT,
    `id_shop`     INT(11)      NOT NULL DEFAULT 1,
    `id_client`   INT(11)      NOT NULL,
    `id_employee` INT(11)      NOT NULL,
    `scopes`      VARCHAR(500) NOT NULL DEFAULT '',
    `date_add`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_upd`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_consent`),
    UNIQUE KEY `uq_consent` (`id_client`, `id_employee`, `id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_log - security events. Never carries a token, a code or a secret.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_log` (
    `id_log`      INT(11)      NOT NULL AUTO_INCREMENT,
    `id_shop`     INT(11)      NOT NULL DEFAULT 1,
    `event`       VARCHAR(50)  NOT NULL,
    `outcome`     VARCHAR(10)  NOT NULL DEFAULT 'info' COMMENT 'info, success or failure',
    `client_id`   VARCHAR(255) NOT NULL DEFAULT '',
    `id_employee` INT(11)      NOT NULL DEFAULT 0,
    `message`     TEXT,
    `ip_address`  VARCHAR(45)  NOT NULL DEFAULT '',
    `date_add`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_log`),
    KEY `idx_date` (`date_add`),
    KEY `idx_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_upload - an invitation to deposit files, and its limits. A tool that
-- needs files cannot be handed them through the protocol, so it opens a row
-- here and sends the merchant to a page of their own back office.
--
-- The reference is stored hashed, like every other secret the module holds:
-- the string itself lives in the sealed request state and in the address the
-- merchant opens, and nowhere else.
--
-- `id_employee` is the account the invitation was opened for, and the page
-- refuses anyone else - that is the identity check a URL-mode elicitation
-- requires. `extensions`, `max_files` and `max_bytes` are what the asking tool
-- declared, already clamped to what this installation can receive.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_upload` (
    `id_upload`      INT(11)      NOT NULL AUTO_INCREMENT,
    `reference_hash` VARCHAR(64)  NOT NULL,
    `id_shop`        INT(11)      NOT NULL DEFAULT 1,
    `id_employee`    INT(11)      NOT NULL,
    `module`         VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'module that asked, for the journal',
    `extensions`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'space separated, what this tool accepts',
    `purpose`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'shown to the merchant',
    `max_files`      INT(11)      NOT NULL DEFAULT 1,
    `max_bytes`      INT(11)      NOT NULL DEFAULT 8388608,
    `expires_at`     DATETIME     NOT NULL,
    `date_add`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_upload`),
    UNIQUE KEY `uq_upload_reference` (`reference_hash`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_upload_file - one row per file actually deposited. Separate from the
-- invitation because a tool may ask for several, and because a merchant who
-- picked the wrong file has to be able to drop that one alone.
--
-- `stored_as` is the module's own name on disk; `file_name` is what the
-- merchant called it and is never used to reach the filesystem.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_upload_file` (
    `id_upload_file` INT(11)      NOT NULL AUTO_INCREMENT,
    `id_upload`      INT(11)      NOT NULL,
    `file_name`      VARCHAR(255) NOT NULL DEFAULT '',
    `mime_type`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'from the verified extension, not from the upload',
    `stored_as`      VARCHAR(64)  NOT NULL DEFAULT '',
    `file_size`      INT(11)      NOT NULL DEFAULT 0,
    `date_add`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_upload_file`),
    KEY `idx_upload` (`id_upload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_assistant - one row per assistant the merchant connected for the chat
-- built into the back office: a provider, the merchant's own API key and how
-- much model they want to pay for.
--
-- `api_key` is encrypted with the shop's own key before it is stored, so a
-- database dump alone does not hand out the merchant's provider account.
-- `model` is empty unless the merchant picked one by hand: the level then
-- resolves to the newest model of that tier, read from the provider.
-- `id_shop` and `id_shop_group` give the level the assistant belongs to, as
-- for any setting: a shop (id_shop), a group (id_shop_group) or all shops
-- (both 0). A shop uses the assistants of the most precise level that has any.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_assistant` (
    `id_assistant`   INT(11)      NOT NULL AUTO_INCREMENT,
    `provider`       VARCHAR(32)  NOT NULL,
    `api_key`        TEXT         NOT NULL,
    `level`          VARCHAR(16)  NOT NULL DEFAULT 'economy' COMMENT 'economy, balanced or power',
    `model`          VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'chosen by hand, overrides the level',
    `resolved_model` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'what the level resolved to last time',
    `resolved_at`    DATETIME     DEFAULT NULL,
    `role`           VARCHAR(255) NOT NULL DEFAULT '',
    `token_cap`      INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'tokens a month on each shop, 0 for no cap of its own',
    `id_relay`       INT(11)      NOT NULL DEFAULT 0 COMMENT 'local assistants only: the relay that carries their requests',
    `id_shop`        INT(11)      NOT NULL DEFAULT 1,
    `id_shop_group`  INT(11)      NOT NULL DEFAULT 0,
    `active`         TINYINT(1)   NOT NULL DEFAULT 1,
    `is_default`     TINYINT(1)   NOT NULL DEFAULT 0,
    `date_add`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_upd`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_assistant`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_relay - a somcp-relay program linked to the shop, which carries the
-- requests of a local assistant to an AI running on the merchant's computer.
-- The relay always calls the shop; the shop never reaches into the merchant's
-- network.
--
-- Only hashes are looked up: `code_hash` while the pairing code is valid,
-- `token_hash` once paired. `code` keeps the code itself, encrypted like an
-- API key, only so the back office can show it again until it is used.
-- `models` is what the relay last reported, JSON: id and tool test result.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_relay` (
    `id_relay`       INT(11)      NOT NULL AUTO_INCREMENT,
    `id_shop`        INT(11)      NOT NULL DEFAULT 1,
    `id_shop_group`  INT(11)      NOT NULL DEFAULT 0,
    `code_hash`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'sha256 of the pairing code, empty once used',
    `code`           TEXT         NULL COMMENT 'the pairing code, encrypted, until it is used',
    `code_expires`   DATETIME     NULL DEFAULT NULL,
    `token_hash`     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'sha256 of the relay token, never the token',
    `relay_version`  VARCHAR(32)  NOT NULL DEFAULT '',
    `relay_os`       VARCHAR(64)  NOT NULL DEFAULT '',
    `software`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'the local AI the relay reported',
    `models`         TEXT         NULL,
    `last_seen`      DATETIME     NULL DEFAULT NULL,
    `date_add`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_relay`),
    KEY `idx_code` (`code_hash`),
    KEY `idx_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_relay_job - one chat request waiting for a relay, then its answer.
-- The chat writes the request and waits; the relay takes it, has the local AI
-- answer and posts the answer back. `job_key` is the id the relay sees, random
-- so that one job cannot be guessed from another.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_relay_job` (
    `id_job`         INT(11)      NOT NULL AUTO_INCREMENT,
    `id_relay`       INT(11)      NOT NULL,
    `job_key`        VARCHAR(64)  NOT NULL,
    `status`         VARCHAR(8)   NOT NULL DEFAULT 'pending' COMMENT 'pending, taken, done or failed',
    `request`        LONGTEXT     NOT NULL COMMENT 'OpenAI-compatible chat body, JSON',
    `http_status`    INT(11)      NOT NULL DEFAULT 0,
    `response`       LONGTEXT     NULL,
    `error`          VARCHAR(255) NOT NULL DEFAULT '',
    `date_add`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_upd`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_job`),
    UNIQUE KEY `uq_job_key` (`job_key`),
    KEY `idx_relay` (`id_relay`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_assistant_usage - what each assistant consumed, per shop and calendar
-- month: an assistant set for several shops is counted for each one, since a
-- cap applies to each shop. The cap is checked against the sum of `cost`
-- before every call.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_assistant_usage` (
    `id_assistant`   INT(11)        NOT NULL,
    `id_shop`        INT(11)        NOT NULL DEFAULT 1,
    `month`          CHAR(7)        NOT NULL COMMENT 'YYYY-MM',
    `input_tokens`   BIGINT         NOT NULL DEFAULT 0,
    `output_tokens`  BIGINT         NOT NULL DEFAULT 0,
    `cost`           DECIMAL(14,6)  NOT NULL DEFAULT 0 COMMENT 'US dollars, estimated from public prices',
    PRIMARY KEY (`id_assistant`, `id_shop`, `month`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_assistant_thread - one row per conversation, and who it belongs to.
-- The framework behind the chat keys everything by thread id and checks no
-- ownership itself, so an employee only ever reaches a thread listed here
-- under their own id. The switches of the chat's + menu are kept here too, so
-- a conversation reopened later goes on as it was set.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_assistant_thread` (
    `id_thread`      INT(11)      NOT NULL AUTO_INCREMENT,
    `thread_id`      VARCHAR(64)  NOT NULL,
    `id_employee`    INT(11)      NOT NULL,
    `id_shop`        INT(11)      NOT NULL DEFAULT 1,
    `id_assistant`   INT(11)      NOT NULL DEFAULT 0 COMMENT 'picked by hand in the chat, 0 for the default one',
    `title`          VARCHAR(255) NOT NULL DEFAULT '',
    `files`          TEXT         NULL COMMENT 'images generated in the conversation, JSON: name and upload reference',
    `memory_last`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'id of the last message written to the long-term memory',
    `think`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'deep thinking asked for this conversation',
    `web_search`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'web search allowed in this conversation',
    `memory`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 when the employee switched the memory off for this conversation',
    `tools`          TEXT         NULL COMMENT 'shop tools the model loaded in this conversation, JSON list of names',
    `busy_until`     DATETIME     NULL DEFAULT NULL COMMENT 'set while a move runs on the conversation, so a reload or a second tab does not start it twice',
    `pending`        TEXT         NULL COMMENT 'the message being answered, JSON: text and file names, shown until the framework has stored it',
    `date_add`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_upd`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_thread`),
    UNIQUE KEY `uq_thread_id` (`thread_id`),
    KEY `idx_employee` (`id_employee`, `date_upd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_assistant_message - the messages of every conversation, in the shape
-- Neuron AI's SQL message store reads and writes. The identifiers are binary
-- because the store needs them compared exactly, which a case-insensitive
-- collation would not do.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_assistant_message` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `thread_id`      VARBINARY(255)  NOT NULL,
    `message_id`     VARBINARY(64)   NOT NULL,
    `role`           VARCHAR(32)     NOT NULL,
    `content`        LONGTEXT        NULL,
    `meta`           LONGTEXT        NULL,
    `archived_at`    DATETIME        NULL,
    `created_at`     DATETIME        DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_thread_message` (`thread_id`, `message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_assistant_run - where Neuron AI keeps a conversation that is waiting:
-- for the merchant to approve a change, or for the next step of a tool. Its
-- own encoding, ASCII only, as the framework requires.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_assistant_run` (
    `partition`      VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `key`            VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `value`          LONGTEXT CHARACTER SET ascii NOT NULL,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`partition`, `key`)
) ENGINE=InnoDB;
