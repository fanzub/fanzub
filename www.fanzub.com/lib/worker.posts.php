<?php // coding: latin1
/**
 * Fanzub - Posts Worker
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');

class Posts extends Cron
{
  const TIME_LIMIT            = 120; // 2 minutes
  const MAX_TIME_LIMIT        = 300; // 5 minutes
  const SPAM_TIME_LIMIT       = 10;
  const ARTICLE_LIMIT         = 10000;
  
  const STRATEGY_PRIMARY      = 1;
  const STRATEGY_ALT          = 2;
  const STRATEGY_IGNORE       = 3;
  
  const MATCH_RANGE           = 1209600; // 2 weeks
  const MATCH_RECENT          =   86400; // 1 day
  const RECENT_THRESHOLD      =    3600; // 1 hour
  
  const MIN_POST_FILES        =      3; // Post must consist of at least 3 parts -OR-
  const MIN_POST_SIZE         =     10; // Post must be at least 10 MB
  const MIN_POST_PERCENTAGE   =     50; // If part number known, at least 50% must be posted
  const MAX_POST_FILES        =    200; // If post contains more parts than this, alternative query might be better
  const MAX_POST_SIZE         =   2000; // If post is larger than this, alternative query might be better
  const MAX_POST_FILES_LIMIT  =  10000; // If post contains more parts than this, ignore (anomaly or spam)
  const MAX_POST_SIZE_LIMIT   = 100000; // If post is larger than this, ignore (anomaly or spam)
  
  const SPAM_POST_THRESHOLD   = 10;
  const SPAM_SIZE_THRESHOLD   = 10;
  
  protected $sql = null;
  protected $sql_alt = null;
  protected $parts = null;

  public function __construct()
  {
    parent::__construct();
    $this->Limits('1024M');
  }
  
  public function Run()
  {
    echo $this->Title('Posts');
    $total_new = 0;
    $total_found = 0;
    $total_ignored = 0;
    // Lets not bother with spammers
    $skipauthors = array();
    $query = 'SELECT "authorid",COUNT(*) AS "total",SUM("hidden") AS "hidden" FROM "posts" GROUP BY "authorid" ';
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      // Total posts equals hidden posts
      if (($row['total'] == $row['hidden']) && ($row['hidden'] > self::SPAM_POST_THRESHOLD))
        $skipauthors[$row['authorid']] = $row['authorid'];
    }
    // Process new articles by author - each author gets 30 seconds so that spam can't hold up useful posts
    $authors = array();
    $query = 'SELECT "articles"."authorid","authors"."name" FROM "articles" '
            .'LEFT JOIN "authors" ON ("authors"."id" = "articles"."authorid") '
            .'WHERE ("articles"."postid" = 0) AND ("articles"."created" > ?) '
            .(count($skipauthors) > 0 ? 'AND ("articles"."authorid" NOT IN ('.implode(',',$skipauthors).')) ' : '')
            .'GROUP BY "articles"."authorid" '
            .'ORDER BY RAND() '; // Ensures that if a single author still holds up script too long, next time luck might change
    $rs = static::$conn->Execute($query,(time()-self::MATCH_RECENT));
    while ($row = $rs->Fetch())
      $authors[$row['authorid']] = $row['name'];
    foreach ($authors as $author_id=>$author_name)
    {
      try {
        // Begin transaction
        static::$conn->BeginTransaction();
        $start = GetMicroTime();
        echo '<p><i>Processing articles posted by <b>'.SafeHTML($author_name).'</b></i><br />';
        $count = 0;
        $new = 0;
        $found = 0;
        $ignored = 0;
        $skip = array();
        while (GetMicroTime() - $start < self::TIME_LIMIT)
        {
          // Get article not yet associated with a post
          $query = 'SELECT "id","authorid","subject","post_date" FROM "articles" '
                  .'WHERE ("postid" = 0) AND ("authorid" = ?) AND ("created" > ?) '
                  .(count($skip) > 0 ? 'AND ("id" > '.intval(end($skip)).') ' : '')
                  .'ORDER BY "id" ASC LIMIT 0,1';
          $rs = static::$conn->Execute($query,$author_id,(time()-self::MATCH_RECENT));
          $row = $rs->Fetch();
          // Abort if no more articles
          if (!$row)
            break;
          // Reset
          $this->sql = $row['subject'];
          $this->sql_alt = $row['subject'];
          $this->parts = null;
          $this->pos = null;
          // Create Queries
          $this->CreateQueries();
          echo SafeHTML($row['subject']).' - ';
          $strategy = $this->DetermineStrategy($row);
          // If first pass failed, try a second time
          if ($strategy == self::STRATEGY_IGNORE)
            $strategy = $this->SecondPass($row);
          // Skip ignored
          if ($strategy == self::STRATEGY_IGNORE)
          {
            $ignored++;
            $skip[$row['id']] = $row['id'];
            echo ' - <i><b style="color:#990000">Ignored</b></i><br />';
            continue;
          }
          // Find existing post (if any)
          $query = 'SELECT "postid" FROM "articles" WHERE ("postid" != 0) AND ("subject" LIKE ?) AND ("authorid" = ?) AND ("post_date" > ?) AND ("post_date" < ?) LIMIT 0,1';
          $rs = static::$conn->Execute($query,($strategy == self::STRATEGY_PRIMARY ? $this->sql : $this->sql_alt),$row['authorid'],$row['post_date']-self::MATCH_RANGE,$row['post_date']+self::MATCH_RANGE);
          $match = $rs->Fetch();
          if ($match)
          {
            $found++;
            $post = Post::FindByID($match['postid']);
            echo ' - <i><b style="color:#000099">Found</b></i><br />';
          }
          else
          {
            $new++;
            $post = new Post();
            // We need the post ID so save first
            $post->Save();
            echo ' - <i><b style="color:#009900">New</b></i><br />';
          }
          // Update articles
          $query = 'UPDATE "articles" SET "postid" = ?  WHERE ("postid" = 0) AND ("subject" LIKE ?) AND ("authorid" = ?) AND ("post_date" > ?) AND ("post_date" < ?)';
          static::$conn->Execute($query,$post->id,($strategy == self::STRATEGY_PRIMARY ? $this->sql : $this->sql_alt),$row['authorid'],$row['post_date']-self::MATCH_RANGE,$row['post_date']+self::MATCH_RANGE);
          // Queue post recalculation + post group update (initializes all missing fields for new posts too)
          Post::Queue($post->id);
          // Sleep for 0.1 seconds to give database time to process
          usleep(100000);
          if ($count > self::ARTICLE_LIMIT)
            break;
          $count++;
        }
        // Update all posts in one go (in case one post was updated more than once)
        $posts = Post::Update();
        // Commit transaction
        static::$conn->Commit();
      } catch (Exception $e) {
        // Rollback on error
        static::$conn->Rollback();
        throw $e;
      }
      echo '<b>'.$new.'</b> posts added, <b>'.$found.'</b> posts updated, <b>'.$ignored.'</b> posts ignored (<b>'.number_format(GetMicroTime()-$start,2).'</b> seconds)</p>';
      $total_new += $new;
      $total_found += $found;
      $total_ignored += $ignored;
      // Abort if processing all authors takes to much time
      if (GetMicroTime() - $this->time['start'] > self::MAX_TIME_LIMIT)
        break;
    }
    echo '<p><i>Total <b>'.$total_new.'</b> posts added, <b>'.$total_found.'</b> posts updated, <b>'.$total_ignored.'</b> posts ignored</i></p>';
    // Detect spam
    $this->DetectSpam();
    // Update last 1000 number of posts that are marked hidden yet are still being listed (=BAD!)
    $query = 'SELECT "id" FROM "posts" LEFT JOIN "postcat" ON ("postcat"."postid" = "posts"."id") '
            .'WHERE ("postcat"."postid" IS NOT NULL) '
            .'AND ("posts"."hidden" = 1) '
            .'ORDER BY "posts"."id" DESC '
            .'LIMIT 0,1000 ';
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      $post = Post::Find($row['id']);
      $post->Save();
    }
    // Update last 100 number of posts that are not being listed even though they're not marked hidden (ie: they were still incomplete when last time encountered)
    $query = 'SELECT "id" FROM "posts" LEFT JOIN "postcat" ON ("postcat"."postid" = "posts"."id") '
            .'WHERE ("postcat"."postid" IS NULL) '
            .'AND ("posts"."hidden" = 0) '
            .'AND ("posts"."post_date" < '.(time()-600).') '
            .'AND ("posts"."files" > 1) '
            .'AND ("posts"."files" < '.self::MAX_POST_FILES_LIMIT.') '
            .'AND ("posts"."size" >= 1048576) '
            .'ORDER BY "posts"."post_date" DESC '
            .'LIMIT 0,100 ';
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      $post = Post::Find($row['id']);
      $post->Save();
    }
    // Update last 100 number of posts that have been last updated longest time ago
    $rs = static::$conn->Execute('SELECT "id" FROM "posts" ORDER BY "updated" ASC LIMIT 0,100 ');
    while ($row = $rs->Fetch())
    {
      $post = Post::Find($row['id']);
      $post->Save();
    }
    PostCat::Update();
  }
  
  protected function CreateQueries()
  {
    // Replace existing wildcard characters
    $this->sql = str_replace('%','\%',$this->sql);
    $this->sql = str_replace('_','\_',$this->sql);
    $this->sql_alt = str_replace('%','\%',$this->sql_alt);
    $this->sql_alt = str_replace('_','\_',$this->sql_alt);
    // Replace article part indicator at end of subject ie: "(1/80)"
  	if (preg_match_all('/(\d+[\/-]\d+)/',$this->sql,$matches))
  	{
  		// Match only last sequence
  		$match = end($matches[1]);
  		// Replace sequence
  		$this->sql = substr_replace($this->sql,'%',strrpos($this->sql,$match),strlen($match));
  		$this->sql_alt = $this->sql;
  	}
    // Modify filename if available, otherwise apply limits to entire subject line	
    if (preg_match_all('/(.*)?(")(.*)(")(.*)?/',$this->sql,$matches))
    {
      // Filename (indicated by quotes) in subject
      $filename = end($matches[3]);
      $replace = '%';
      // Remove everything beyond *last* space (if any)
      if (preg_match('/(jpg|jpeg|gif|png|mp3|ogg|flac)/i',$filename,$matches) && (strpos($filename,' ') !== false))
      	$replace = substr($filename,0,strrpos($filename,' ')).'%';
      // Remove everything beyond *last* underscore (if any)
      elseif (preg_match('/(jpg|jpeg|gif|png|mp3|ogg|flac)/i',$filename,$matches) && (strpos($filename,'_') !== false))
      	$replace = substr($filename,0,strrpos($filename,'_')).'%';
      // Remove everything beyond *last* dash (if any)
      elseif (preg_match('/(jpg|jpeg|gif|png|mp3|ogg|flac)/i',$filename,$matches) && (strpos($filename,'-') !== false))
      	$replace = substr($filename,0,strrpos($filename,'-')).'%';
      // Remove everything beyond *last* dot (if any)
      elseif (strpos($filename,'.') !== false)
      	$replace = substr($filename,0,strrpos($filename,'.')).'%';
      // Remove ".part00" from filename (numbers optional)
      $replace = preg_replace('/(\.)(part)(\d+)?(\.)/i','%',$replace);
      // Remove ".vol00+01.par2" from filename
      $replace = preg_replace('/(\.)(vol\d+\+\d+)/i','%',$replace);
      // Remove various double file extensions
      $replace = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$replace);
      // Repeat
      $replace = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$replace);
      // Remove additional double file extensions
      $replace = preg_replace('/(\.)(par)/i','%',$replace);
      // Remove duplicate % chars
      $replace = preg_replace('/\%+/','%',$replace);
      // Escape $ chars (will conflict with preg_replace)
      $replace = str_replace('$','\\$',$replace);
      // Apply replacement pattern to queries
      $this->sql = preg_replace('/(.*)?(")(.*)(")(.*)?/','${1}${2}%${4}${5}',$this->sql);
      $this->sql_alt = preg_replace('/(.*)?(")(.*)(")(.*)?/','${1}${2}'.$replace.'${4}${5}',$this->sql_alt);
  	}
  	elseif ((strpos($this->sql,'.') !== false) && (substr_count($this->sql,'.') > 1) && (substr_count($this->sql,'.') <= 3))
  	{
  		$this->sql = substr($this->sql,0,strpos($this->sql,'.')).'%';
  		$this->sql_alt = substr($this->sql_alt,0,strpos($this->sql_alt,'.')).'%';
  	}
    // Remove additional elements from subject -if- filename can not be detected
    if (!preg_match_all('/(.*)?(")(.*)(")(.*)?/',$this->sql,$matches))
    {
      // Remove ".part00" from filename (numbers optional)
      $this->sql = preg_replace('/(\.)(part)(\d+)?(\.)/i','%',$this->sql);
      $this->sql_alt = preg_replace('/(\.)(part)(\d+)?(\.)/i','%',$this->sql_alt);
      // Remove ".vol00+01.par2" from filename
      $this->sql = preg_replace('/(\.)(vol\d+\+\d+)/i','%',$this->sql);
      $this->sql_alt = preg_replace('/(\.)(vol\d+\+\d+)/i','%',$this->sql_alt);
      // Remove various double file extensions
      $this->sql = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$this->sql);
      $this->sql_alt = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$this->sql_alt);
      // Repeat
      $this->sql = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$this->sql);
      $this->sql_alt = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$this->sql_alt);
      // Remove additional double file extensions
      $this->sql = preg_replace('/(\.)(par)/i','%',$this->sql);
      $this->sql_alt = preg_replace('/(\.)(par)/i','%',$this->sql_alt);
      // Remove duplicate % chars
      $this->sql = preg_replace('/(\\\\)(\%{2,})/','%',$this->sql);
      $this->sql = preg_replace('/\%{2,}/','%',$this->sql);
      $this->sql_alt = preg_replace('/(\\\\)(\%{2,})/','%',$this->sql_alt);
      $this->sql_alt = preg_replace('/\%{2,}/','%',$this->sql_alt);
    }
  	// Remove file size indicators
  	$this->sql = preg_replace('/(\d+)([\.|,]*)(\d*)(\s*)(bytes|byte|kbytes|kbyte|kb|kib|k|mbytes|mbyte|mb|mib|m|gbytes|gbyte|gb|gib|g)/i','%',$this->sql);
  	$this->sql_alt = preg_replace('/(\d+)([\.|,]*)(\d*)(\s*)(bytes|byte|kbytes|kbyte|kb|kib|k|mbytes|mbyte|mb|mib|m|gbytes|gbyte|gb|gib|g)/i','%',$this->sql_alt);
    // Try to detect file part in subject
    if (preg_match_all('/([\[\(])(\d+|\*)(\s*[\\\\\/-]\s*)(\d+)([\)\]])|([\[\(]*)(\d+|\*)(\s*of\s*|\s*von\s*)(\d+)([\)\]]*)/i',$this->sql,$matches)) // "[X/X]" notation: brackets mandatory
    {
      $match = end($matches[0]);
      $part = end($matches[2]);
      $pos = strlen(end($matches[1]));
      $this->parts = (int)end($matches[4]);
      // Match alternative style if empty
      if (empty($part))
      {
        $part = end($matches[7]);
        $pos = strlen(end($matches[6]));
        $this->parts = (int)end($matches[9]);
      }
    }
    // Only replace part number if total number of parts is more than 1 (=pointless post)
    if (!is_null($this->parts) && ($this->parts > 1))
    {
      
      $this->sql = substr_replace($this->sql,'%',strrpos($this->sql,$match)+$pos,strlen($part));
      $this->sql_alt = substr_replace($this->sql_alt,'%',strrpos($this->sql_alt,$match)+$pos,strlen($part));
    }
    // For better alt query match, remove any and all part numbers
    $result = preg_match_all('/([\[\(]*)(\d+|\*)(\s*of\s*|\s*von\s*|\s*[\\\\\/-]\s*)(\d+)([\)\]]*)/i',$this->sql_alt,$matches);
    if ($result)
    {
      // Only replace part indicators that are NOT at the start of the subject line, otherwise matching is very db intensive
      foreach ($matches[0] as $key=>$match)
        if (strpos($this->sql_alt,$match) > 0)
          $this->sql_alt = str_replace($match,'%',$this->sql_alt);
      $this->sql_alt = preg_replace('/(\\\\)(\%{2,})/','%',$this->sql_alt);  
      $this->sql_alt = preg_replace('/\%{2,}/','%',$this->sql_alt);  
    }
    // Check if query without filename too generic (would cause high database load if run)
    $meta = trim(str_ireplace(array('yEnc','[',']','(',')','%','/','\\','-','.','"'),'',$this->sql));
    if ((strlen($meta) == 0) || ((string)$meta === (string)(int)$meta))
      $this->sql = null;
    // Also discard normal query if there is no part indicator (otherwise to many articles may accidentially be grouped into one post)
    if (is_null($this->parts))
      $this->sql = null;
  }
  
  protected function DetermineStrategy($row)
  {
    // Avoid having % character at start of query to preven high database load (and potentially very broad matching)
    if ((!is_null($this->sql) && (substr($this->sql,0,1) == '%')) || (!is_null($this->sql_alt) && (substr($this->sql_alt,0,1) == '%')))
    {
      echo '<i>(query may not have wildcard at beginning)</i>';
      return self::STRATEGY_IGNORE;
    }
    // Count matches
    if (!is_null($this->sql))
      list($files,$size) = $this->CountMatches($this->sql,$row['authorid'],$row['post_date']);
    list($alt_files,$alt_size,$maxdate) = $this->CountMatches($this->sql_alt,$row['authorid'],$row['post_date'],true);
    // If primary query is already discarded, ALT strategy is only option
    if (is_null($this->sql) || is_null($this->parts))
    {
      // Use IGNORE strategy if post has few files AND small file size (if either is bigger, allow post)
      if (($alt_files < self::MIN_POST_FILES) || (($alt_size < self::MIN_POST_SIZE) && !in_array($row['authorid'],$GLOBALS['goodauthors'])))
      {
        echo '<i>(no primary; alt too small)</i>';
        return self::STRATEGY_IGNORE;
      }
      // Use IGNORE strategy if post has few files
      if ($alt_files < self::MIN_POST_FILES)
      {
        echo '<i>(no primary; alt too few files)</i>';
        return self::STRATEGY_IGNORE;
      }
      // Use IGNORE strategy if post is *massive*
      if (($alt_files > self::MAX_POST_FILES_LIMIT) || ($alt_size > self::MAX_POST_SIZE_LIMIT))
      {
        echo '<i>(no primary; alt way to big)</i>';
        return self::STRATEGY_IGNORE;
      }
      echo '<i>(no primary; using alt)</i>';
      return self::STRATEGY_ALT;
    }
    // If primary query and alternative query are the same, then use PRIMARY strategy
    if ($this->sql == $this->sql_alt)
    {
      // Use IGNORE strategy if post has few files AND small file size (if either is bigger, allow post)
      if (($files < self::MIN_POST_FILES) || (($size < self::MIN_POST_SIZE) && !in_array($row['authorid'],$GLOBALS['goodauthors'])))
      {
        echo '<i>(identical queries; too small)</i>';
        return self::STRATEGY_IGNORE;
      }
      // Use IGNORE strategy if post has few files
      if ($files < self::MIN_POST_FILES)
      {
        echo '<i>(identical queries; too few files)</i>';
        return self::STRATEGY_IGNORE;
      }
      echo '<i>(identical queries; using primary)</i>';
      return self::STRATEGY_PRIMARY;
    }
    // Use IGNORE strategy if post has few files AND small file size (if either is bigger, allow post)
    if (($files < self::MIN_POST_FILES) || (($size < self::MIN_POST_SIZE) && !in_array($row['authorid'],$GLOBALS['goodauthors'])))
    {
      echo '<i>(too small)</i>';
      return self::STRATEGY_IGNORE;
    }
    // Use ALT strategy if to many parts found
    if ($files > ($this->parts + 1))
    {
      // Use IGNORE strategy if post has few files
      if ($alt_files < self::MIN_POST_FILES)
      {
        echo '<i>(too many parts found; alt too few files)</i>';
        return self::STRATEGY_IGNORE;
      }
      echo '<i>(too many parts found; using alt)</i>';
      return self::STRATEGY_ALT;
    }
    // If post is HUGE, check alternative query
    if (($files > self::MAX_POST_FILES) || ($size > self::MAX_POST_SIZE))
    {
      // Use IGNORE strategy if post has few files AND small file size AND is recent (as presumably post is still being uploaded)
      if (($maxdate > (time() - self::MATCH_RECENT)) && ($alt_files < self::MIN_POST_FILES) && ($alt_size < self::MIN_POST_SIZE))
      {
        echo '<i>(huge; recent; alt too small)</i>';
        return self::STRATEGY_IGNORE;
      }
      // Use IGNORE strategy if post is *massive*
      if (($alt_files > self::MAX_POST_FILES_LIMIT) || ($alt_size > self::MAX_POST_SIZE_LIMIT))
      {
        echo '<i>(huge; alt way to big)</i>';
        return self::STRATEGY_IGNORE;
      }
      // Use ALT strategy if alternative query is not small AND at no more than 50% of total parts (=if almost the same, skip it)
      if (((((float)$alt_files / (float)$this->parts)*100) < self::MIN_POST_PERCENTAGE) && ($alt_files > self::MIN_POST_FILES))
      {
        echo '<i>(huge; using alt)</i>';
        return self::STRATEGY_ALT;
      }
    }
    // Before assuming primary query is best choice, check if there are no duplicate part numbers
    $dup = 0;
    $parts = array();
    $query = 'SELECT "subject" FROM "articles" WHERE ("subject" LIKE ?) AND ("authorid" = ?) AND ("post_date" > ?) AND ("post_date" < ?)';
    $rs = static::$conn->Execute($query,$this->sql,$row['authorid'],$row['post_date']-self::MATCH_RANGE,$row['post_date']+self::MATCH_RANGE);
    while ($row = $rs->Fetch())
    {
      // Article part indicator may interfere; remove it first
      preg_match_all('/(\d+[\/-]\d+)/',$row['subject'],$matches);
      $subject = substr_replace($row['subject'],'%',strrpos($row['subject'],end($matches[1])),strlen(end($matches[1])));
      // Find post part number
      preg_match_all('/([\[\(]*)(\d+|\*)(\s*of\s*|[\\\\\/-])(\d+)([\)\]]*)/i',$subject,$matches);
      $part = intval(end($matches[2]));
      // Count duplicates
      if (($part > 0) && isset($parts[$part]))
        $dup++;
      $parts[$part] = $part;
    }
    // Use ALT if more than 20% duplicate parts found (and more than 1 duplicate)
    if (($dup > 1) && ($dup > ((float)$this->parts / 20)))
    {
      // Use IGNORE strategy if post has few files
      if ($alt_files < self::MIN_POST_FILES)
      {
        echo '<i>(duplicate parts; alt too few files)</i>';
        return self::STRATEGY_IGNORE;
      }
      echo '<i>(duplicate parts; using alt)</i>';
      return self::STRATEGY_ALT;
    }
    // Use IGNORE strategy if post has just 1 file
    if (($files < self::MIN_POST_FILES) && ($alt_files < self::MIN_POST_FILES))
    {
      echo '<i>(both primary and alt too few files)</i>';
      return self::STRATEGY_IGNORE;
    }
    elseif (($files < self::MIN_POST_FILES) && ($alt_files > self::MIN_POST_FILES))
    {
      echo '<i>(primary too few files; using alt)</i>';
      return self::STRATEGY_ALT;
    }
    // Use PRIMARY strategy if all checks cleared
    echo '<i>(all checks cleared; using primary)</i>';
    return self::STRATEGY_PRIMARY;
  }
  
  protected function RoundDate($date)
  {
    // Round date to nearest hour, for less unique queries leading to better MySQL query cache matching
    return (int)round((float)$date / 3600) * 3600;
  }
  
  protected function CountMatches($subject,$author,$date,$return_max = false,$return_posts = false)
  {
    $query = 'SELECT COUNT(*) AS "files", SUM("size") AS "size"'.($return_max ? ', MAX("post_date") AS "maxdate" ' : ' ')
            .'FROM "articles" WHERE ("subject" LIKE ?) AND ("authorid" = ?) AND ("post_date" > ?) AND ("post_date" < ?)';
    $rs = static::$conn->Execute($query,$subject,$author,self::RoundDate($date-self::MATCH_RANGE),self::RoundDate($date+self::MATCH_RANGE));
    $row = $rs->Fetch();
    if ($return_max && $return_posts)
    {
      // Return distinct post IDs (obviously not counting post ID = 0)
      $query = 'SELECT COUNT(DISTINCT "postid") AS "posts" '
              .'FROM "articles" WHERE ("subject" LIKE ?) AND ("authorid" = ?) AND ("post_date" > ?) AND ("post_date" < ?) AND ("postid" != 0) ';
      $rs = static::$conn->Execute($query,$subject,$author,self::RoundDate($date-self::MATCH_RANGE),self::RoundDate($date+self::MATCH_RANGE));
      $row2 = $rs->Fetch();
      return array($row['files'],round($row['size']/(1024*1024)),$row['maxdate'],$row2['posts']);
    }
    elseif ($return_max)
      return array($row['files'],round($row['size']/(1024*1024)),$row['maxdate']);
    else
      return array($row['files'],round($row['size']/(1024*1024)));
  }
  
  protected function SecondPass($row)
  {
    // Old style matching by removing common extensions (etc)
    $this->sql = $row['subject'];
    // Replace existing wildcard characters
    $this->sql = str_replace('%','\%',$this->sql);
    $this->sql = str_replace('_','\_',$this->sql);
		// Remove "[number] jpg" at end of string
		$this->sql = preg_replace('/\(?\d{0,4}[a-z]?\)?\.(jpg|jpeg|gif|png|mp3|ogg|flac)/i','%',$this->sql);
    // Remove ".part00" from filename (numbers optional)
    $this->sql = preg_replace('/(\.)(part)(\d+)?(\.)/i','%',$this->sql);
    // Remove ".vol00+01.par2" from filename
    $this->sql = preg_replace('/(\.)(vol\d+\+\d+)/i','%',$this->sql);
    // Remove various double file extensions
    $this->sql = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$this->sql);
    // Repeat
    $this->sql = preg_replace('/(\.)(\d{3,4}|sfv|md5|nzb|nfo|rar|r\d+|zip|7z|lzh|par2|avi|mkv|ogm|mp4|mpg|mpeg|srt|ssa)/i','%',$this->sql);
    // Remove additional double file extensions
    $this->sql = preg_replace('/(\.)(par)/i','%',$this->sql);
    // For better alt query match, remove any and all part numbers
    $this->sql = preg_replace('/([\[\(]*)(\d+|\*)(\s*of\s*|\s*von\s*|\s*[\\\\\/-]\s*)(\d+)([\)\]]*)/i','%',$this->sql);
		// Remove number in between quotes (fixes articles by some idiots)
		$this->sql = preg_replace('/(")\d+(")/i','%',$this->sql);
    // Remove file size indicators
    $this->sql = preg_replace('/(\d+)([\.|,]*)(\d*)(\s*)(bytes|byte|kbytes|kbyte|kb|kib|k|mbytes|mbyte|mb|mib|m|gbytes|gbyte|gb|gib|g)/i','%',$this->sql);
    // Remove certain keywords
    $this->sql = preg_replace('/\s*([\[\(]*)(nzb|sfv|md5|nfo)([\)\]]*)\s*/i','%',$this->sql);
    // Remove duplicate % chars
    $this->sql = preg_replace('/(\\\\)(\%{2,})/','%',$this->sql);
    $this->sql = preg_replace('/\%\s+\%/','%',$this->sql);
    $this->sql = preg_replace('/\%{2,}/','%',$this->sql);
    // Check articles
    list($files,$size,$maxdate,$posts) = $this->CountMatches($this->sql,$row['authorid'],$row['post_date'],true,true);
    // Found post
    if (($posts == 1) && ($files >= self::MIN_POST_FILES) && (($size >= self::MIN_POST_SIZE) || in_array($row['authorid'],$GLOBALS['goodauthors'])))
    {
      echo ' - <i>(second pass: old style)</i>';
      return self::STRATEGY_PRIMARY;
    }
    // New post
    if (($posts == 0) && ($maxdate < (time()-self::RECENT_THRESHOLD)) && ($files >= self::MIN_POST_FILES) && (($size >= self::MIN_POST_SIZE) || in_array($row['authorid'],$GLOBALS['goodauthors'])))
    {
      echo ' - <i>(second pass: old style)</i>';
      return self::STRATEGY_PRIMARY;
    }
    // Start over again; very wide matching by simply removing whole filename (if one exists between quotes)
    $this->sql = $row['subject'];
    // Replace existing wildcard characters
    $this->sql = str_replace('%','\%',$this->sql);
    $this->sql = str_replace('_','\_',$this->sql);
    // Find filename and eliminate it, then try to match articles
    if (preg_match_all('/(.*)?(")(.*)(")(.*)?/',$this->sql,$matches))
    {
      // Filename (indicated by quotes) in subject
      $filename = end($matches[3]);
      $this->sql = str_replace($filename,'%',$this->sql);
      // For better alt query match, remove any and all part numbers
      $result = preg_match_all('/([\[\(]*)(\d+|\*)(\s*of\s*|\s*von\s*|\s*[\\\\\/-]\s*)(\d+)([\)\]]*)/i',$this->sql,$matches);
      if ($result)
      {
        // Only replace part indicators that are NOT at the start of the subject line, otherwise matching is very db intensive
        foreach ($matches[0] as $key=>$match)
          if (strpos($this->sql,$match) > 0)
            $this->sql = str_replace($match,'%',$this->sql);
      }
    	// Remove file size indicators
    	$this->sql = preg_replace('/(\d+)([\.|,]*)(\d*)(\s*)(bytes|byte|kbytes|kbyte|kb|kib|k|mbytes|mbyte|mb|mib|m|gbytes|gbyte|gb|gib|g)/i','%',$this->sql);
      // Remove certain keywords
      $this->sql = preg_replace('/\s*([\[\(]*)(nzb|sfv|md5|nfo)([\)\]]*)\s*/i','%',$this->sql);
      // Remove duplicate % chars
      $this->sql = preg_replace('/(\\\\)(\%{2,})/','%',$this->sql);
      $this->sql = preg_replace('/\%\s+\%/','%',$this->sql);
      $this->sql = preg_replace('/\%{2,}/','%',$this->sql);
      // Check articles
      list($files,$size,$maxdate,$posts) = $this->CountMatches($this->sql,$row['authorid'],$row['post_date'],true,true);
      // Found post
      if (($posts == 1) && ($maxdate < (time()-self::RECENT_THRESHOLD)) && ($files >= self::MIN_POST_FILES) && (($size >= self::MIN_POST_SIZE) || in_array($row['authorid'],$GLOBALS['goodauthors'])))
      {
        echo ' - <i>(second pass: filename removed)</i>';
        return self::STRATEGY_PRIMARY;
      }
      // New post
      if (($posts == 0) && ($maxdate < (time()-self::RECENT_THRESHOLD)) && ($files >= self::MIN_POST_FILES) && (($size >= self::MIN_POST_SIZE) || in_array($row['authorid'],$GLOBALS['goodauthors'])))
      {
        echo ' - <i>(second pass: filename removed)</i>';
        return self::STRATEGY_PRIMARY;
      }
    }
    // Use IGNORE strategy if second pass failed
    echo ' - <i>(second pass: failed)</i>';
    return self::STRATEGY_IGNORE;
  }
  
  protected function DetectSpam()
  {
    $spam = 0;
    $ids = array();
    // .EXE files are instantly suspect as virus or spam
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("stats" LIKE \'%exe%\') '
            .'AND ("subject" NOT LIKE \'%rockman%\') '                             // Skip known anime series with EXE in name
            .'AND ("subject" NOT LIKE \'%megaman%\') '                             // Skip known anime series with EXE in name
            .'AND ("subject" NOT LIKE \'%baldr%\') '                               // Skip known anime series with EXE in name
            .'AND ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) ' // Skip good authors
            .'AND ("hidden" = 0) ';                                                // Only match posts not already hidden
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      $ids[$row['id']] = intval($row['id']);
      Post::Queue($row['id']);
    }
    // Posts with keyword "keygen" are 99.999% probable spam
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("subject" LIKE \'%keygen%\') '
            .'AND ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) ' // Skip good authors
            .'AND ("hidden" = 0) ';                                                // Only match posts not already hidden
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      $ids[$row['id']] = intval($row['id']);
      Post::Queue($row['id']);
    }
    // Posts with keyword "compressed]" (with bracket at end) are 99.999% probable spam
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("subject" LIKE \'%compressed]%\') '
            .'AND ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) ' // Skip good authors
            .'AND ("hidden" = 0) ';                                                // Only match posts not already hidden
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      $ids[$row['id']] = intval($row['id']);
      Post::Queue($row['id']);
    }
    // Posts with keywords "crack" *and* "serial" are 99.999% probable spam
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("subject" LIKE \'%crack%\') AND ("subject" LIKE \'%serial%\') '
            .'AND ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) ' // Skip good authors
            .'AND ("hidden" = 0) ';                                                // Only match posts not already hidden
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      $ids[$row['id']] = intval($row['id']);
      Post::Queue($row['id']);
    }
    // Posts with keyword "WMV" are 99.999% probable spam
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("subject" LIKE \'%wmv%\') '
            .'AND ("stats" NOT LIKE \'%par%\') '
            .'AND ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) '  // Skip good authors
            .'AND ("hidden" = 0) '                                                  // Only match posts not already hidden
            .'AND ("updated" < ?) ';
    $rs = static::$conn->Execute($query,time()-self::RECENT_THRESHOLD);
    while ($row = $rs->Fetch())
    {
      $ids[$row['id']] = intval($row['id']);
      Post::Queue($row['id']);
    }
    // Match posts as spam if an article matches the subject of an article from existing spam post
    $start = GetMicroTime();
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) '  // Skip good authors
            .'AND ("hidden" = 0) '                                                    // Only match posts not already hidden
            .'AND ("updated" > ?) '
            .'ORDER BY RAND() ';
    $rs = static::$conn->Execute($query,time()-self::MATCH_RECENT);
    while ($row = $rs->Fetch())
    {
      // Find all articles associated with a post
      $rs2 = static::$conn->Execute('SELECT "id","subject" FROM "articles" WHERE ("postid" = ?) ',$row['id']);
      while ($row2 = $rs2->Fetch())
      {
        $subject = $row2['subject'];
        $result = preg_match_all('/([\[\(]*)(\d+|\*)(\s*of\s*|\s*von\s*|\s*[\\\\\/-]\s*)(\d+)([\)\]]*)/i',$row2['subject'],$matches);
        if ($result)
        {
          // Only replace part indicators that are NOT at the start of the subject line, otherwise matching is very db intensive
          foreach ($matches[0] as $key=>$match)
            if (strpos($row2['subject'],$match) > 0)
              $subject = str_replace($match,'%',$subject);
          $subject = preg_replace('/\%{2,}/','%',$subject);
        }
        // Count number of articles with same subject that are associated with posts marked as hidden
        $query = 'SELECT COUNT(*) AS "total" FROM "articles" INNER JOIN "posts" ON ("posts"."id" = "articles"."postid") '
                .'WHERE ("articles"."id" != ?) '
                .'AND ("articles"."postid" > 0) '
                .'AND ("articles"."subject" LIKE ?) '
                .'AND ("posts"."hidden" = 1) ';
        $rs3 = static::$conn->Execute($query,$row2['id'],$subject);
        $row3 = $rs3->Fetch();
        if ($row3['total'] > 0)
        {
          // Non-zero response = subject matches known spam post
          $ids[$row['id']] = intval($row['id']);
          Post::Queue($row['id']);
        }
        // These checks can be extremely time consuming, so limit time allowed
        if (GetMicroTime() - $start > self::SPAM_TIME_LIMIT)
          break 2;
      }
    }
    // Find spam by checking all authors who made posts without any typical files like NZB, NFO, SFV etc.
    $start = GetMicroTime();
    $query = 'SELECT "authorid",subject FROM "posts" '
            .'WHERE ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) '  // Skip good authors
            .'AND ("hidden" = 0) '                                                    // Only match posts not already hidden
            .'AND ("updated" > ?) '
            .'AND ("updated" < ?) '
            .'GROUP BY "authorid" '
            .'ORDER BY RAND() ';
    $rs = static::$conn->Execute($query,time()-self::MATCH_RECENT,time()-self::RECENT_THRESHOLD);
    while ($row = $rs->Fetch())
    {
      // First get data on all posts
      $query = 'SELECT COUNT(*) AS "total", SUM("files") AS "total_files",ROUND((SUM("size") / SUM("files")) / (1024*1024)) AS "avg_size" FROM "posts" '
              .'WHERE ("authorid" = ?) '
              .'AND ("updated" > ?) ';
      $rs2 = static::$conn->Execute($query,$row['authorid'],time()-self::MATCH_RANGE);
      $row2 = $rs2->Fetch();
      // Then get data on all posts except those with typical files (NZB, NFO, SFV etc)
      $query = 'SELECT COUNT(*) AS "total", SUM("files") AS "total_files",ROUND((SUM("size") / SUM("files")) / (1024*1024)) AS "avg_size" FROM "posts" '
              .'WHERE ("authorid" = ?) '
              .'AND ("stats" NOT LIKE \'%nzb%\') '
              .'AND ("stats" NOT LIKE \'%sfv%\') '
              .'AND ("stats" NOT LIKE \'%md5%\') '
              .'AND ("stats" NOT LIKE \'%nfo%\') '
              .'AND ("stats" NOT LIKE \'%jpg%\') '
              .'AND ("stats" NOT LIKE \'%gif%\') '
              .'AND ("stats" NOT LIKE \'%png%\') '
              .'AND ("stats" NOT LIKE \'%mp3%\') '
              .'AND ("stats" NOT LIKE \'%ogg%\') '
              .'AND ("stats" NOT LIKE \'%flac%\') '
              .'AND ("updated" > ?) ';
      $rs3 = static::$conn->Execute($query,$row['authorid'],time()-self::MATCH_RANGE);
      $row3 = $rs3->Fetch();
      // Only process authors that do not post any NZB, NFO, SFV (etc) files in ANY of their posts at all (total posts from both queries above is the SAME)
      if (($row2['total'] == $row3['total']) && ($row2['total'] > self::SPAM_POST_THRESHOLD) && ($row2['avg_size'] < self::SPAM_SIZE_THRESHOLD)) 
      {
        $rs4 = static::$conn->Execute('SELECT "id" FROM "posts" WHERE ("authorid" = ?) AND ("hidden" = 0) ',$row['authorid']);
        while ($row4 = $rs4->Fetch())
        {
          $ids[$row4['id']] = intval($row4['id']);
          Post::Queue($row4['id']);
        }
      }
      // These checks can be extremely time consuming, so limit time allowed
      if (GetMicroTime() - $start > self::SPAM_TIME_LIMIT)
        break;
    }
    // Author 34 (Default Yenc Power-Post user) is suspect - mark any post without typical files (NZB, NFO, SFV etc) as spam
    $query = 'SELECT "id" FROM "posts" '
            .'WHERE ("authorid" = 34) '
            .'AND ("stats" NOT LIKE \'%nzb%\') '
            .'AND ("stats" NOT LIKE \'%sfv%\') '
            .'AND ("stats" NOT LIKE \'%md5%\') '
            .'AND ("stats" NOT LIKE \'%nfo%\') '
            .'AND ("stats" NOT LIKE \'%jpg%\') '
            .'AND ("stats" NOT LIKE \'%gif%\') '
            .'AND ("stats" NOT LIKE \'%png%\') '
            .'AND ("stats" NOT LIKE \'%mp3%\') '
            .'AND ("stats" NOT LIKE \'%ogg%\') '
            .'AND ("stats" NOT LIKE \'%flac%\') '
            .'AND ("hidden" = 0) '
            .'AND ("updated" > ?) '
            .'AND ("updated" < ?) ';
    $rs = static::$conn->Execute($query,time()-self::MATCH_RECENT,time()-self::RECENT_THRESHOLD);
    while ($row = $rs->Fetch())
    {
      $ids[$row['id']] = intval($row['id']);
      Post::Queue($row['id']);
    }
    // Check authors with high spam percentage and mark all remaining posts as spam as well (IMPORTANT: only works if "hidden" flag is at most "1" and NOT any higher!)
    $query = 'SELECT "authorid",ROUND((SUM("hidden") / COUNT(*)) * 100) AS "spam", COUNT(*) AS "total", SUM("hidden") AS "total_hidden" FROM "posts" '
            .'WHERE ("authorid" NOT IN ('.implode(',',$GLOBALS['goodauthors']).')) '  // Skip good authors
            .'AND ("authorid" NOT IN (34,201)) '                                      // Also skip default user names for certain posting software
            .'GROUP BY "authorid" ';
    $rs = static::$conn->Execute($query);
    while ($row = $rs->Fetch())
    {
      // Continue if spam percentage is 20% or higher but not already 100%
      if (($row['spam'] > 20) && ($row['total'] != $row['total_hidden']))
      {
        $rs2 = static::$conn->Execute('SELECT "id" FROM "posts" WHERE ("authorid" = ?) AND ("hidden" = 0) ',$row['authorid']);
        while ($row2 = $rs2->Fetch())
        {
          $ids[$row2['id']] = intval($row2['id']);
          Post::Queue($row2['id']);
        }
      }
      // Alternatively if author has one or more hidden files (but not 100%) and one of them is a .EXE file assume everything is spam
      if (($row['total_hidden'] > 0) && ($row['total'] != $row['total_hidden']))
      {
        $rs2 = static::$conn->Execute('SELECT COUNT(*) AS "total" FROM "posts" WHERE ("authorid" = ?) AND ("hidden" = 1) AND ("stats" LIKE \'%exe%\') ',$row['authorid']);
        $row2 = $rs2->Fetch();
        if ($row2['total'] > 0)
        {
          // .EXE file found: mark all as spam
          $rs2 = static::$conn->Execute('SELECT "id" FROM "posts" WHERE ("authorid" = ?) AND ("hidden" = 0) ',$row['authorid']);
          while ($row2 = $rs2->Fetch())
          {
            $ids[$row2['id']] = intval($row2['id']);
            Post::Queue($row2['id']);
          }
        }
      }
    }
    // Mark all collected post IDs as hidden
    if (count($ids) > 0)
    {
      static::$conn->Execute('UPDATE "posts" SET "hidden" = 1, "updated" = UNIX_TIMESTAMP() WHERE "id" IN ('.implode(',',$ids).') ');
      $spam += static::$conn->affected_rows;
    }
    echo '<p>Marked <b>'.$spam.'</b> posts as spam.</p>';    
    // Update posts (mainly to remove postcat for entries that are now hidden)
    Post::Update();
  }
}
?>