-- $Id$

CREATE TABLE downloads (
	postid INTEGER UNSIGNED NOT NULL,
	userip VARCHAR(50) NOT NULL,
	created INTEGER UNSIGNED NOT NULL,
	PRIMARY KEY(postid,userip)
);
