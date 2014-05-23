<?php // coding: latin1
/**
 * Fanzub - Headers Worker
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');

class NZB extends Cron
{
  const TIME_LIMIT = 30;
  
  public function __construct()
  {
    parent::__construct();
    $this->Limits('1024M');
  }

  public function Run($id = null)
  {
    echo $this->Title('NZB');
    $count = 0;
    $query = 'SELECT "postcat"."postid","posts"."subject" FROM "postcat" LEFT JOIN "posts" ON ("posts"."id" = "postcat"."postid") '
            .'WHERE ("postcat"."nzb_date" < "postcat"."updated") ORDER BY "postcat"."post_date" DESC ';
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      echo $row['postid'].' :: '.SafeHTML($row['subject']).'<br />';
      // Create parent folder if it doesn't exist yet
      if (!file_exists(dirname(Post::NZBFile($row['postid']))))
        mkdir(dirname(Post::NZBFile($row['postid'])),0755,true);
      // Write NZB file
      file_put_contents(Post::NZBFile($row['postid']),Post::NZB($row['postid']));
      // Update DB
      if (file_exists(Post::NZBFile($row['postid'])))
        static::$conn->AutoUpdate('postcat',array('nzb_date' => time()),'postid = ?',$row['postid']);
      $count++;
      // Abort if processing if to much NZB files to process
      if (GetMicroTime() - $this->time['start'] > self::TIME_LIMIT)
        break;
    }
    echo '<p><b>'.$count.'</b> NZB files processed.</p>';
  }
}
?>