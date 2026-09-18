#
# Reports are an audit log: only the delivery state and the review state
# (open/resolved) change after creation. The tables deliberately have no TCA,
# so reports cannot be edited through the List module or DataHandler.
# Screenshots are stored in tx_contextreporter_attachment.content.
#
CREATE TABLE tx_contextreporter_report (
	uid int(11) unsigned NOT NULL auto_increment,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	identifier varchar(32) DEFAULT '' NOT NULL,
	reporter_uid int(11) unsigned DEFAULT '0' NOT NULL,
	source varchar(32) DEFAULT '' NOT NULL,
	title varchar(255) DEFAULT '' NOT NULL,
	description text,
	subject_type varchar(16) DEFAULT '' NOT NULL,
	subject_table varchar(255) DEFAULT '' NOT NULL,
	subject_uid int(11) unsigned DEFAULT '0' NOT NULL,
	subject_label varchar(255) DEFAULT '' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	summary text,
	context mediumtext,
	delivery_state varchar(16) DEFAULT 'local' NOT NULL,
	review_state varchar(16) DEFAULT 'open' NOT NULL,
	resolved_at int(11) unsigned DEFAULT '0' NOT NULL,
	resolved_by int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY identifier (identifier),
	KEY created (crdate),
	KEY reporter (reporter_uid,crdate)
);

CREATE TABLE tx_contextreporter_attachment (
	uid int(11) unsigned NOT NULL auto_increment,
	report_uid int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	attachment_type varchar(32) DEFAULT '' NOT NULL,
	filename varchar(255) DEFAULT '' NOT NULL,
	media_type varchar(64) DEFAULT '' NOT NULL,
	size int(11) unsigned DEFAULT '0' NOT NULL,
	width int(11) unsigned DEFAULT '0' NOT NULL,
	height int(11) unsigned DEFAULT '0' NOT NULL,
	sha256 varchar(64) DEFAULT '' NOT NULL,
	content mediumblob,

	PRIMARY KEY (uid),
	KEY report (report_uid)
);

CREATE TABLE tx_contextreporter_delivery (
	uid int(11) unsigned NOT NULL auto_increment,
	report_uid int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	destination varchar(64) DEFAULT '' NOT NULL,
	attempt int(11) unsigned DEFAULT '0' NOT NULL,
	status varchar(16) DEFAULT '' NOT NULL,
	target varchar(255) DEFAULT '' NOT NULL,
	triggered_by int(11) unsigned DEFAULT '0' NOT NULL,
	response_code int(11) unsigned DEFAULT '0' NOT NULL,
	message text,
	external_reference varchar(255) DEFAULT '' NOT NULL,
	external_url text,
	duration_ms int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY report (report_uid,destination)
);
