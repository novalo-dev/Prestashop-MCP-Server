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

-- somcp_agent_conversation - one conversation of the back office assistant.
--
-- Owned by the employee who started it and by the shop it was held on: an
-- employee reads their own history, never a colleague's, and a conversation
-- about one shop does not follow them onto another.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_agent_conversation` (
    `id_conversation` INT(11)      NOT NULL AUTO_INCREMENT,
    `id_employee`     INT(11)      NOT NULL,
    `id_shop`         INT(11)      NOT NULL DEFAULT 1,
    `title`           VARCHAR(255) NOT NULL DEFAULT '',
    `provider`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'anthropic, openai or google',
    `model`           VARCHAR(100) NOT NULL DEFAULT '',
    `date_add`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_upd`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_conversation`),
    KEY `idx_employee_shop` (`id_employee`, `id_shop`, `date_upd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- somcp_agent_message - one turn of a conversation, in a shape of the module's
-- own that every provider is translated to and from.
--
-- `role` is user, assistant or tool. `content` is JSON: the text, the tool
-- calls an assistant turn asked for, or the result of one call. `status` marks
-- a call waiting for the employee to approve it, so the page can ask again
-- after a reload instead of forgetting the question.
CREATE TABLE IF NOT EXISTS `PREFIX_somcp_agent_message` (
    `id_message`      INT(11)      NOT NULL AUTO_INCREMENT,
    `id_conversation` INT(11)      NOT NULL,
    `role`            VARCHAR(10)  NOT NULL,
    `content`         MEDIUMTEXT   NOT NULL,
    `status`          VARCHAR(10)  NOT NULL DEFAULT 'done' COMMENT 'done or pending',
    `date_add`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_message`),
    KEY `idx_conversation` (`id_conversation`, `id_message`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
