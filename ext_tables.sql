CREATE TABLE tx_otalerts_events (
    uid              int(11) unsigned NOT NULL AUTO_INCREMENT,
    source           varchar(100)     NOT NULL DEFAULT '',
    event_key        varchar(200)     NOT NULL DEFAULT '',
    severity         varchar(20)      NOT NULL DEFAULT 'error',
    status           varchar(20)      NOT NULL DEFAULT 'new',
    first_occurrence int(11) unsigned NOT NULL DEFAULT 0,
    last_occurrence  int(11) unsigned NOT NULL DEFAULT 0,
    last_notified    int(11) unsigned NOT NULL DEFAULT 0,
    occurrence_count int(11) unsigned NOT NULL DEFAULT 0,
    last_message     text,
    PRIMARY KEY (uid),
    UNIQUE KEY source_event (source(100), event_key(200))
);
