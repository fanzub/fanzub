fanzub
======

This repository contains the source code for Fanzub.com, an Usenet search engine for Japanese media. I don't really download much these days (partially due to watching more and more anime on DVD instead of as fansub) and have kind of lost interest in the site.

For this reason I've decided to make the source code of the site available through Github. Feel free to fork it and start your own Fanzub-like site. Don't expect me to respond to pull requests though.

To get this code working you need some experience as a (PHP) web developer and Linux server admin, as there is no manual beyond this readme. I'm willing to answer questions about the code, but I won't hold your hand all the way. The source code includes my own crappy attempt at a PHP framework as well as lots of regular expression magic, so beware.

### Requirements
* PHP 5+
* MySQL 5+
* Memcached
* Sphinx Search
* Adequate versions of above software are included with Ubuntu 12.04 LTS, newer software will probably work too (no guarantees)
* The database is approximately 10 GB and has a table with 10 million rows. You probably don't want to try to host the site on shared hosting, use at least a VPS instead with enough space for both the SQL dump and the restored database.

### Configuration
* **Apache**  
  The Apache (*lib/fanzub-apache2.conf*) is pretty straight forward except for several "alias" commands that allow URLs like fanzub.com/help without a trailing .php extension. Adapting the configuration for nginx or other webserver shouldn't be difficult.
  
* **Sphinx Search**  
  Any search operation on Fanzub requires [Sphinx Search](http://sphinxsearch.com/) to function. You can find the config file in *lib/sphinx-config.conf*, be sure to set the database password here too and fix the paths if necessary. Don't forget to configure cron jobs to update the Sphinx Search index frequently: I used the Ubuntu defaults (*/etc/cron.d/sphinxsearch*) of a daily index rebuild and updates every 5 minutes.
  
* **Usenet servers**  
  If you want index new posts you'll need one or more Usenet providers to download headers from. I used four: [Hitnews](http://www.hitnews.com/), [Astraweb](http://www.news.astraweb.com/), [Giganews](http://www.giganews.com/) and [Newshosting](http://www.newshosting.com/). You can certainly use less (even just one) if you want. Out of the four Astraweb might be a good choice as they offer block accounts which means you don't need to pay a monthly subscription (and headers typically don't count towards your download limit). The configuration file for the servers is *lib/usenet.ini.php*
  
* **File permissions**  
  The following folders (and contents) need to be writeable by the same user as the webserver (on Debian/Ubuntu this is the "www-data" user):
  *www.fanzub.com/data/*
  *www.fanzub.com/www/logs/*
  
* **PHP exceptions**   
  Exceptions are stored in a small SQLite database in *www.fanzub.com/data/journal.db*. Make sure this file exists and is writeable by the webserver. You can create the database using the *journal.sql* file from the *sql/* folder:
  *sqlite3 journal.db < ../sql/journal.sql*
  
* **Cron jobs**   
  If you want to index new posts you need to run one script (*scripts/headers*) often. Make sure this file is executable. See *scripts/fanzub.cron* for an example. You may need to change it to reflect the number of servers you use and to prevent high load on your server.
  
* **Database**  
  The database schema can be found in *sql/fanzub-schema.sql*. The database data dump can be downloaded from: [https://fanzub.com/dump/](https://fanzub.com/dump/). The data dump contains all tables *except* the "downloads" table, which is related to a feature I never finished and contains hashed IP addresses (hence why I'm not including it). To prevent errors you might first want to restore the *fanzub-schema.sql* file before restoring the data dump so that your database will include an empty "downloads" table.
  
  As mentioned before, please note that the database is huge. You'll need at least 20~25 GB free space to restore it (which will take a while).
  
### Questions
If you have any questions you can ask them here: http://forums.animesuki.com/showthread.php?t=81306 I can give no guarantee I'll reply in a timely fashion.