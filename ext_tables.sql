CREATE TABLE tx_dokaudit_file (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    file int(11) unsigned DEFAULT '0' NOT NULL,
    storage int(11) unsigned DEFAULT '0' NOT NULL,
    identifier varchar(1024) DEFAULT '' NOT NULL,
    status varchar(32) DEFAULT 'new' NOT NULL,
    message varchar(1024) DEFAULT '' NOT NULL,
    document_id varchar(64) DEFAULT '' NOT NULL,
    sha1_original varchar(40) DEFAULT '' NOT NULL,
    sha1_fixed varchar(40) DEFAULT '' NOT NULL,
    backup_identifier varchar(1024) DEFAULT '' NOT NULL,
    seals varchar(64) DEFAULT '' NOT NULL,
    hub_url varchar(255) DEFAULT '' NOT NULL,
    score_before int(11) DEFAULT NULL,
    score_after int(11) DEFAULT NULL,
    pages int(11) DEFAULT NULL,
    submitted_at int(11) unsigned DEFAULT '0' NOT NULL,
    processed_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY file (file),
    KEY status (status)
);
