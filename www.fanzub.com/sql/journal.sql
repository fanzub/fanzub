-- $Id$

CREATE TABLE journal (
  id INTEGER NOT NULL PRIMARY KEY,
  level TEXT NOT NULL,
  class TEXT NOT NULL,
  userid INTEGER NOT NULL DEFAULT 0,
  userip TEXT NOT NULL,
  message TEXT NOT NULL,
  details TEXT NOT NULL DEFAULT '',
  request TEXT NOT NULL DEFAULT '',
  script TEXT NOT NULL,
  object TEXT NOT NULL DEFAULT '',
  repeats INTEGER NOT NULL DEFAULT 0,
  date_added INTEGER NOT NULL,
  date_repeat INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX date_added ON journal (date_added);
