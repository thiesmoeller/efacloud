CREATE TABLE IF NOT EXISTS portal_checkouts (
    logbook_name VARBINARY(255) NOT NULL,
    trip_ecrid VARBINARY(64) NOT NULL,
    user_id BIGINT NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (logbook_name, trip_ecrid),
    INDEX by_account (user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS portal_idempotency (
    user_id BIGINT NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, key_hash)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS portal_mutation_lock (
    id INT PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO portal_mutation_lock (id) VALUES (1);
