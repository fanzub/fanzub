<?php // coding: latin1
/**
 * Fanzub
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */

/*** FOR DEBUG PURPOSES ***/
#error_reporting(E_STRICT|E_ALL);
#define('DEBUG',true);
#ini_set('display_errors',1);
/*** SHOULD NOT BE USED ON LIVE SITE ***/

// Config
$config = parse_ini_file('../lib/config.ini.php',true);
$config += parse_ini_file('../lib/usenet.ini.php',true);

require_once('../lib/framework/class.standard.php');
require_once('../lib/framework/class.mysql.php');
require_once('../lib/framework/class.activerecord.php');
require_once('../lib/framework/class.framework.php');
require_once('../lib/framework/class.sphinx.php');

// Database
$conn = new MySQL($config['db']['server'],$config['db']['database'],$config['db']['username'],$config['db']['password']);

// Cache
$cache = new Cache();

// Defines
define('CAT_ANIME',1);
define('CAT_DRAMA',2);
define('CAT_MUSIC',3);
define('CAT_RAWS',4);
define('CAT_HENTAI',5);
define('CAT_GAMES',6);
define('CAT_DVD',7);
define('CAT_HMANGA',8);

$catgroup = array();
$catgroup[CAT_ANIME] = array(1,3,4,5);
$catgroup[CAT_DRAMA] = array(9,10);
$catgroup[CAT_MUSIC] = array(2,13);
$catgroup[CAT_RAWS] = array(6);
$catgroup[CAT_HENTAI] = array(7);
$catgroup[CAT_GAMES] = array(8);
$catgroup[CAT_DVD] = array(11,12);
$catgroup[CAT_HMANGA] = array(14);

$groupcat = array(1 => CAT_ANIME,
                  2 => CAT_MUSIC,
                  3 => CAT_ANIME,
                  4 => CAT_ANIME,
                  5 => CAT_ANIME,
                  6 => CAT_RAWS,
                  7 => CAT_HENTAI,
                  8 => CAT_GAMES,
                  9 => CAT_DRAMA,
                  10 => CAT_DRAMA,
                  11 => CAT_DVD,
                  12 => CAT_DVD,
                  13 => CAT_MUSIC,
									14 => CAT_HMANGA);

$catname = array(CAT_ANIME 	=> 'anime',
                 CAT_DRAMA 	=> 'drama',
                 CAT_MUSIC 	=> 'music',
                 CAT_RAWS 	=> 'raws',
                 CAT_HENTAI	=> 'hentai',
                 CAT_GAMES	=> 'games',
                 CAT_DVD 		=> 'dvd',
								 CAT_HMANGA	=> 'hmanga');

$goodauthors = array(  14, // Bell-chan
										   16, // Icabod
											 17, // Happosai
										   18, // Fabien
											 22, // Fabien
											 29, // mechazawa
											 40, // NoNo
											 42, // user
											 48, // Same Guy
											 52, // myc400
											 53, // Nagumi-kun
											 54, // Happosai
											101, // GrimReaper
											142, // Arne Weise
											348, // funuke
											488, // Sharon Williams
										 1765, // BlackSwordsman
										 2179, // no-one-u-know
										 2256, // Lenmaer
										 2298, // GrimReaper
										 2418, // vide-mio
										 2547, // Comfun
										 2625, // comfun
										 2728, // skuld
										 3652, // MaxBigfoot
										 3654, // 4everlost
										 3667, // xander drax
										 3668, // Tsaryu
										 3684, // -DarkNinja-
										 3688, // -DarkNinja-
										 3697, // Reddy Kilowatt
										 3705, // Otaku
										 3931 // WG
										 );

class Newsgroup extends ActiveRecord
{
  protected static $table = 'newsgroups';
  protected static $columns = null;
	protected static $lookup = array();
	
	public static function Lookup($id)
	{
		if (!isset(static::$lookup[(int)$id]))
		{
			$rs = static::$conn->Execute('SELECT * FROM "'.static::$table.'" WHERE "id" = ? ',$id);
			$row = $rs->Fetch();
			if ($row)
				static::$lookup[$row['id']] = $row['name'];
			else
				return null; // Not found
		}
		return static::$lookup[(int)$id];
	}
}

class Author extends ActiveRecord
{
  protected static $table = 'authors';
  protected static $columns = null;
	protected static $lookup = array();
	
	public static function Lookup($id)
	{
		if (!isset(static::$lookup[(int)$id]))
		{
			$rs = static::$conn->Execute('SELECT * FROM "'.static::$table.'" WHERE "id" = ? ',$id);
			$row = $rs->Fetch();
			if ($row)
				static::$lookup[$row['id']] = $row['name'];
			else
				return null; // Not found
		}
		return static::$lookup[(int)$id];
	}
}

class Article extends ActiveRecord
{
  const MATCH_RANGE = 604800; // 1 week
  
  protected static $table = 'articles';
  protected static $columns = null;
	protected static $authors = array();
	protected static $postqueue = array();

	/**
	 * Magic method for setting properties.
   */ 
	public function __set($name,$value)
	{
		switch($name)
		{
      case 'post_date':
        if ($value > time())
          $this->changes[$name] = time();
        else
          $this->changes[$name] = $value;
        break;
      
			default:
        parent::__set($name,$value);
		}
  }
  
  public function Load(array $row)
  {
    parent::Load($row);
    if (strlen($this->parts) != 0)
      $this->fields['parts'] = unserialize(gzinflate($this->fields['parts']));
  }
  
  public function Save()
  {
    if (isset($this->changes['parts']))
    {
      if (isset($this->changes['parts']['size']))
        $this->size = array_sum($this->changes['parts']['size']);
      if (isset($this->changes['parts']['id']))
        $this->parts_found = count($this->changes['parts']['id']);
      $this->changes['parts'] = gzdeflate(serialize($this->changes['parts']));
    }
    if (is_null($this->_key))
      $this->created = time();
    $this->updated = time();
    return parent::Save();
  }
  
  public static function Match($authorid,$subject,$date)
  {
    static::Init();
    // Build query
    $query = 'SELECT * FROM "'.static::$table.'" '
            .'WHERE ("subject" = ?) '
            .'AND ("authorid" = ?) '
            .'AND ("post_date" > ?) '
            .'AND ("post_date" < ?) '
            .'LIMIT 0,1';
    $rs = static::$conn->Execute($query,$subject,$authorid,$date-self::MATCH_RANGE,$date+self::MATCH_RANGE);
    // No rows = raise error
    if ($rs->num_rows == 0)
      throw new ActiveRecord_NotFoundException('Row not found in table <i>'.SafeHTML(static::$table).'</i>');
    $row = $rs->Fetch();
    $instance = new static();
    $instance->Load($row);
    return $instance;
  }
  
  public function IsComplete()
  {
    return ($this->parts_found >= $this->parts_total);
  }
  
  protected static function Author($name)
  {
    if (empty($name))
      return 0;
    elseif (isset(static::$authors[$name]))
      return static::$authors[$name];
    else
    {
      try {
        $author = Author::FindByName($name);
      } catch (ActiveRecord_NotFoundException $e) {
        $author = new Author();
        $author->name = $name;
        $author->Save();
      }
      static::$authors[$name] = $author->id;
      return $author->id;
    }
  }
	
	/*
	 * Update: matches headers to existing entry or returns new instance
	 * $author = name of author (string)
	 * $subject = full subject line
	 * $date = date of earliest article part
	 * $total = total number of article parts
	 * $groups = array of group ids where headers were found in
	 * $parts = array of parts (format: [size][number] = bytes and [id][number] = message-id)
	 * Returns true if match found, false when headers are new
	 */ 
	public static function Update($author,$subject,$date,$total,$groups,$parts)
	{
		$authorid = static::Author($author);
		// Remove redundant characters
		foreach ($parts['id'] as $key=>$value)
			$parts['id'][$key] = str_replace(array('<','>'),'',$value);
		try {
			// Found
			$article = Article::Match($authorid,$subject,$date);
			$result = true;
			$article->parts = self::MergeParts($article->parts,$parts);
			if ($article->post_date > $date)
				$article->post_date = $date;
			$article->Save();
			if ($article->postid > 0)
				Post::Queue($article->postid);
		} catch (ActiveRecord_NotFoundException $e) {
			// Not found
			$result = false;
			$article = new Article();
			$article->subject = $subject;
			$article->authorid = $authorid;
			$article->parts_total = $total;
			$article->parts = $parts;
			$article->post_date = $date;
			$article->Save();
			if (is_array($groups))
			{
				foreach ($groups as $group)
				{
					try {
						$articlegroup = new ArticleGroup();
						$articlegroup->articleid = $article->id;
						$articlegroup->groupid = $group;
						$articlegroup->created = $article->created;
						$articlegroup->Save();
					} catch (Exception $e) {
						// Ignore errors
					}
				}
			}
		}
		return $result;
	}
	
	/*
	 * Function merges $part1 and $part2 array while keeping assocations
	 */
	public static function MergeParts($part1,$part2)
	{
		$result = array();
		$result['size'] = array();
		$result['id'] = array();
		foreach ($part1['size'] as $key=>$value)
			$result['size'][$key] = $value;
		foreach ($part2['size'] as $key=>$value)
			$result['size'][$key] = $value;
		foreach ($part1['id'] as $key=>$value)
			$result['id'][$key] = $value;
		foreach ($part2['id'] as $key=>$value)
			$result['id'][$key] = $value;
		ksort($result['size']);
		ksort($result['id']);
		return $result;
	}
}

class ArticleGroup extends ActiveRecord
{
  protected static $table = null;
  protected static $columns = null;
}

class ServerGroup extends ActiveRecord
{
  protected static $table = null;
  protected static $columns = null;

  public function Save()
  {
    $this->checked_date = time();
    return parent::Save();
  }
}

class Post extends ActiveRecord
{
  protected static $table = 'posts';
  protected static $columns = null;
	protected static $queue = array();

  public function Save()
  {
    if ($this->id > 0)
      $this->Recalculate();
    if (is_null($this->_key))
      $this->created = time();
    $this->updated = time();
    parent::Save();
		PostCat::Queue($this->id);
  }
  
  public function Recalculate()
  {
    try {
      $this->size = (float)0;
      $this->files = 0;
      $this->parts_total = 0;
      $this->parts_found = 0;
      $altsize = (float)0;
      $stats = array();
      $articles = Article::Find(null,'SELECT "subject","authorid","parts_total","parts_found","size","post_date" FROM "articles" WHERE "postid" = '.$this->id.' ORDER BY "post_date" ASC');
      if (!is_array($articles))
        $articles = array($articles);
      foreach ($articles as $article)
      {
        $this->files++;
        if (!preg_match('/(\.)(par2|nzb|sfv|md5|nfo)/i',$article->subject,$matches))
          $this->size += (float)$article->size;
        $altsize += (float)$article->size;
        if (empty($this->subject) || empty($this->authorid) || empty($this->post_date) || ($article->post_date > $this->post_date))
        {
          $this->subject = $article->subject;
          $this->authorid = $article->authorid;
          if ($article->post_date < time())
            $this->post_date = $article->post_date;
        }
        $this->parts_total += $article->parts_total;
        $this->parts_found += $article->parts_found;
        // Build stats
        if (stripos($article->subject,'.par2') !== false)
          $stats['par2'] = (isset($stats['par2']) ? $stats['par2'] + 1 : 1);
        elseif (stripos($article->subject,'.exe') !== false)
          $stats['exe'] = (isset($stats['exe']) ? $stats['exe'] + 1 : 1);
        elseif (preg_match('/(\.)(part\d+)(\.rar)/i',$article->subject,$matches))
          $stats['rar'] = (isset($stats['rar']) ? $stats['rar'] + 1 : 1);
        elseif (preg_match('/(\.)(r\d{2,3})/i',$article->subject,$matches))
          $stats['rar'] = (isset($stats['rar']) ? $stats['rar'] + 1 : 1);
        elseif (preg_match('/(\.)(\d{3,4})/i',str_ireplace(array('.264','.480','.720','.1080'),'',$article->subject),$matches))
          $stats['split'] = (isset($stats['split']) ? $stats['split'] + 1 : 1);
        elseif (stripos($article->subject,'.par') !== false)
          $stats['par'] = (isset($stats['par']) ? $stats['par'] + 1 : 1);
        elseif (stripos($article->subject,'.nzb') !== false)
          $stats['nzb'] = (isset($stats['nzb']) ? $stats['nzb'] + 1 : 1);
        elseif (stripos($article->subject,'.sfv') !== false)
          $stats['sfv'] = (isset($stats['sfv']) ? $stats['sfv'] + 1 : 1);
        elseif (stripos($article->subject,'.md5') !== false)
          $stats['md5'] = (isset($stats['md5']) ? $stats['md5'] + 1 : 1);
        elseif (stripos($article->subject,'.rar') !== false)
          $stats['rar'] = (isset($stats['rar']) ? $stats['rar'] + 1 : 1);
        elseif (stripos($article->subject,'.zip') !== false)
          $stats['zip'] = (isset($stats['zip']) ? $stats['zip'] + 1 : 1);
        elseif (stripos($article->subject,'.nfo') !== false)
          $stats['nfo'] = (isset($stats['nfo']) ? $stats['nfo'] + 1 : 1);
        elseif (stripos($article->subject,'.jpg') !== false)
          $stats['jpg'] = (isset($stats['jpg']) ? $stats['jpg'] + 1 : 1);
        elseif (stripos($article->subject,'.jpeg') !== false)
          $stats['jpg'] = (isset($stats['jpg']) ? $stats['jpg'] + 1 : 1);
        elseif (stripos($article->subject,'.gif') !== false)
          $stats['gif'] = (isset($stats['gif']) ? $stats['gif'] + 1 : 1);
        elseif (stripos($article->subject,'.png') !== false)
          $stats['png'] = (isset($stats['png']) ? $stats['png'] + 1 : 1);
        elseif (stripos($article->subject,'.mp3') !== false)
          $stats['mp3'] = (isset($stats['mp3']) ? $stats['mp3'] + 1 : 1);
        elseif (stripos($article->subject,'.ogg') !== false)
          $stats['ogg'] = (isset($stats['ogg']) ? $stats['ogg'] + 1 : 1);
        elseif (stripos($article->subject,'.flac') !== false)
          $stats['flac'] = (isset($stats['flac']) ? $stats['flac'] + 1 : 1);
        else
          $stats['other'] = (isset($stats['other']) ? $stats['other'] + 1 : 1);
      }
      // Check if filtering out files caused size to be zero, if so, forget filtering
      if ($this->size == 0)
        $this->size = $altsize;
      ksort($stats);
      $this->stats = http_build_query($stats);
    } catch (ActiveRecord_NotFoundException $e) {
    }
  }
	
	public static function Queue($postid)
	{
		self::$queue[$postid] = $postid;
	}

	public static function Update()
	{
		$result = 0;
    foreach (self::$queue as $postid)
    {
      try {
        $post = Post::FindByID($postid);
        $post->Save(); // Automatically calls Recalculate() before saving
	      // Update post groups
				try {
					$query = 'SELECT DISTINCT("articlegroup"."groupid") FROM "articles" FORCE INDEX("postid") INNER JOIN "articlegroup" ON ("articlegroup"."articleid" = "articles"."id") WHERE "articles"."postid" = ?';
					$rs = static::$conn->Execute($query,$post->id);
					while ($row = $rs->Fetch())
					{
						try {
							$postgroup = Postgroup::Find(array('postid'=>(int)$post->id,'groupid'=>(int)$row['groupid']));
						} catch (ActiveRecord_NotFoundException $e) {
							// Create
							$postgroup = new Postgroup();
							$postgroup->postid = $post->id;
							$postgroup->groupid = $row['groupid'];
							$postgroup->created = $post->created;
							$postgroup->Save();
						}
					}
				} catch (Exception $e) {
					// Ignore errors
				}
        $result++;
      } catch (Exception $e) {
				// Ignore errors
      }
    }
		PostCat::Update();
		return $result;
	}
	
	public static function FilenameFilter($subject)
	{
		$file = '';
		$desc = '';
		// Apply filters based on whether post has obvious filename or not
		$result = preg_match('/(.*)?(")(.*)(")(.*)?/i',$subject,$matches);
    if ($result)
		{
			$file = $matches[3];
			$desc = $matches[1].' '.$matches[5];
			// Filter out unwanted extentions
			$file = preg_replace('/(\.)(part\d{0,4}(\.rar)?|\d{3,4}|vol\d{1,4}\+\d{1,4}|sfv|md5|nzb|nfo|r\d+|par2)$/i','',$file);
			// Filter out part indicators
			$result = preg_match_all('/([\[\(]*)(\d+|\*)(\s*of\s*)(\d+)([\)\]]*)/i',$desc,$matches);
			if ($result)
			{
				for ($i = 0; $i < $result; $i++)
				{
					if (strlen($matches[2][$i]) == strlen($matches[4][$i]))
						$desc = str_ireplace($matches[0][$i],'',$desc);
				}
			}
			$result = preg_match_all('/([\[\(])(\d+|\*)(\s*[\\\\\/-]\s*)(\d+)([\)\]])/i',$desc,$matches);
			if ($result)
			{
				for ($i = 0; $i < $result; $i++)
				{
					if (strlen($matches[2][$i]) == strlen($matches[4][$i]))
						$desc = str_ireplace($matches[0][$i],'',$desc);
					elseif (($matches[1][$i] == '(') && ($i == ($result-1)))
						$desc = str_ireplace($matches[0][$i],'',$desc);
				}
			}
			// Filter out unwanted keywords
			$desc = str_ireplace('yEnc','',$desc);
			// Filter out file size indicators
			$desc = preg_replace('/(\d+)([\.|,]*)(\d*)(\s*)(bytes|byte|kbytes|kbyte|kb|kib|k|mbytes|mbyte|mb|mib|m|gbytes|gbyte|gb|gib|g) /i','',$desc);
		}
		else
		{
			$file = $subject;
			// Filter out part indicators
			$result = preg_match_all('/([\[\(]*)(\d+|\*)(\s*of\s*)(\d+)([\)\]]*)/i',$file,$matches);
			if ($result)
			{
				for ($i = 0; $i < $result; $i++)
				{
					if (strlen($matches[2][$i]) == strlen($matches[4][$i]))
						$file = str_ireplace($matches[0][$i],'',$file);
				}
			}
			$result = preg_match_all('/([\[\(])(\d+|\*)(\s*[\\\\\/-]\s*)(\d+)([\)\]])/i',$file,$matches);
			if ($result)
			{
				for ($i = 0; $i < $result; $i++)
				{
					if (strlen($matches[2][$i]) == strlen($matches[4][$i]))
						$file = str_ireplace($matches[0][$i],'',$file);
					elseif (($matches[1][$i] == '(') && ($i == ($result-1)))
						$file = str_ireplace($matches[0][$i],'',$file);
				}
			}
			// Filter out unwanted keywords
			$file = str_ireplace('yEnc','',$file);
			// Filter out file size indicators
			$file = preg_replace('/(\d+)([\.|,]*)(\d*)(\s*)(bytes|byte|kbytes|kbyte|kb|kib|k|mbytes|mbyte|mb|mib|m|gbytes|gbyte|gb|gib|g) /i','',$file);
		}
		// Filter out unwanted extentions
		$file = preg_replace('/(\.)(part\d{0,4}(\.rar)?|\d{3,4}|vol\d{1,4}\+\d{1,4}|sfv|md5|nzb|zip|r\d+|par2)$/i','',trim($file));
		$file = preg_replace('/(\.)(part\d{0,4}(\.rar)?|\d{3,4}|vol\d{1,4}\+\d{1,4}|sfv|md5|nzb|zip|r\d+|par2)$/i','',trim($file));
		$desc = preg_replace('/(\.)(part\d{0,4}(\.rar)?|vol\d{1,4}\+\d{1,4}|sfv|md5|nzb|zip|r\d+|par2)/i','',trim($desc));
		$desc = preg_replace('/(\.)(part\d{0,4}(\.rar)?|vol\d{1,4}\+\d{1,4}|sfv|md5|nzb|zip|r\d+|par2)/i','',trim($desc));
		// Filter trailer characters
		$file = preg_replace('/[\-\\\\\/\.]{1}$/','',trim($file));
		$desc = preg_replace('/[\-\\\\\/\.]{1}$/','',trim($desc));
		// Remove "[number] jpg" at end of string
		$file = preg_replace('/\(?\d{0,4}[a-z]?\)?\.(jpg|jpeg|gif|png|mp3|ogg|flac)$/i','',trim($file));
		$desc = preg_replace('/\(?\d{0,4}[a-z]?\)?\.(jpg|jpeg|gif|png|mp3|ogg|flac)$/i','',trim($desc));
		// Remove URL
		$file = preg_replace('/http\:\/\/(.*)/i',' ',trim($file));
		$desc = preg_replace('/http\:\/\/(.*)/i',' ',trim($desc));
		// Remove domain name
		$file = preg_replace('/\s*(www\.)?[a-z]+\.(com|net|org|info)\s*/i',' ',trim($file));
		$desc = preg_replace('/\s*(www\.)?[a-z]+\.(com|net|org|info)\s*/i',' ',trim($desc));
		// Remove redundant strings
		$file = preg_replace('/\s*-\s*disc\s*-\s*/i',' ',trim($file));
		$file = preg_replace('/\s*file\s*\:\s*/i',' ',trim($file));
		$desc = preg_replace('/\s*-\s*disc\s*-\s*/i',' ',trim($desc));
		$desc = preg_replace('/\s*file\s*\:\s*/i',' ',trim($desc));
		// Remove redundant brackets
		$file = preg_replace('/[\(\[]+\s*[\)\]]+/i',' ',trim($file));
		$desc = preg_replace('/[\(\[]+\s*[\)\]]+/i',' ',trim($desc));
		// No underscores
		$file = str_replace('_',' ',trim($file));
		$desc = str_replace('_',' ',trim($desc));
		// Strip excess whitespace
		$file = preg_replace('/\s\s+/',' ',trim($file));
		$file = str_replace('- -',' - ',trim($file));
		$file = preg_replace('/^-\s*/',' ',trim($file));
		$file = preg_replace('/\s*-$/',' ',trim($file));
		$file = trim(preg_replace('/\s\s+/',' ',$file));
		$desc = preg_replace('/\s\s+/',' ',trim($desc));
		$desc = str_replace('- -',' - ',trim($desc));
		$desc = preg_replace('/^-\s*/',' ',trim($desc));
		$desc = preg_replace('/\s*-$/',' ',trim($desc));
		$desc = trim(preg_replace('/\s\s+/',' ',$desc));
		// Trim
		$file = trim($file);
		$desc = trim($desc);
		// Remove duplicate parts and common keywords from description for checking length
		$dedup = trim(str_ireplace(array('non','english','subs','480p','468p','540p','576p','720p','1080p','h264','xvid','mkv','webm','vp8','avi','ogg','flac','mp3','aac','by',',','.','|','-',' ','[',']','!'),'',$desc));
		// If deduplicated description is still longer (=more useful), return both description and file together)
		if (strlen($dedup) > strlen($file))
		{
			// Filter out unwanted keywords
			$file = str_ireplace('[abpea]','',$file);
			$file = str_ireplace('abpea - ','',$file);
			$desc = str_ireplace('[abpea]','',$desc);
			$desc = str_ireplace('abpea - ','',$desc);
			// Process file
			$file = str_replace('.',' ',$file);
			$parts = preg_split('/[\s\_\.]+/',$file,null,PREG_SPLIT_NO_EMPTY);
			foreach ($parts as $part)
				if (stripos($desc,$part) && !is_numeric($part))
					$file = str_ireplace($part,' ',$file);
			$file = preg_replace('/\s\s+/',' ',$file);
			$file = str_replace('- -',' - ',$file);
			$file = trim(preg_replace('/\s\s+/',' ',$file));
			$file = preg_replace('/[\-\\\\\/\.]{1}$/','',trim($file));
			$desc = preg_replace('/[\-\\\\\/\.]{1}$/','',trim($desc));
			return trim($desc).(strlen(trim($file)) > 0 ? ' - '.trim($file) : '');
		}
		// Check if something is left over after filtering, otherwise return unmodified subject line
		if (empty($file))
			$file = $subject;
		return $file;
	}

	public static function NZBName($subject)
	{
    $file = static::FilenameFilter($subject);
    $file = preg_replace('/\.([a-zA-Z0-9]{2,4})$/i','',$file); // No extension at end
    $file = preg_replace('/([a-f0-9]{8})/i','',$file); // No CRC32
    $nzb = '';
    for ($i = 0; $i < strlen($file); $i++)
    {
      if (
          (($file[$i] >= 'a') && ($file[$i] <= 'z')) ||
          (($file[$i] >= 'A') && ($file[$i] <= 'Z')) ||
          (($file[$i] >= '0') && ($file[$i] <= '9')) ||
          ($file[$i] == '-')
         )
        $nzb .= $file[$i];
      else
        $nzb .= ' ';
    }
    $nzb = trim(substr(preg_replace('/\s\s+/',' ',$nzb),0,80));
    if (empty($nzb))
      $nzb = $post->id;
    return $nzb.'.nzb';
	}
	
	public static function NZBFile($postid)
	{
		$postid = intval($postid);
		return $GLOBALS['config']['path']['nzb'].'/'.floor(floor($postid/1000)*1000).'/'.$postid.'.nzb';
	}

	public static function NZB($postid)
	{
		static::Init();
		$result = '';
		// Get newsgroups
		$groups = '';
		$rs = static::$conn->Execute('SELECT "groupid" FROM "postgroup" WHERE "postid" = ? ',$postid);
		while ($row = $rs->Fetch())
			$groups .= "\t\t\t<group>".Newsgroup::Lookup($row['groupid'])."</group>\n";
		// Get files
		$rs = static::$conn->Execute('SELECT "subject","authorid","parts","post_date" FROM "articles" WHERE "postid" = ? ',$postid);
		while ($row = $rs->Fetch())
		{
			if (strlen($row['parts']) == 0)
				continue; // Empty parts value = SKIP file
			$parts = unserialize(gzinflate($row['parts']));
			if (isset($parts['id']) && isset($parts['size']))
			{
				$result .= "\t".'<file poster="'.SafeHTML(Author::Lookup($row['authorid'])).'" date="'.$row['post_date'].'" subject="'.SafeHTML($row['subject']).'">'."\n";
				// Groups
				$result .= "\t\t<groups>\n".$groups."\t\t</groups>\n";
				// Segments
				$result .= "\t\t<segments>\n";
				ksort($parts['size']);
				foreach ($parts['size'] as $key=>$value)
					if (isset($parts['id'][$key]))
						$result .= "\t\t\t".'<segment bytes="'.$value.'" number="'.$key.'">'.SafeHTML($parts['id'][$key])."</segment>\n";
				$result .= "\t\t</segments>\n";
				$result .= "\t</file>\n";
			}
		}
		return $result;
	}
	
	public static function Age($date)
	{
		return floor((time()-$date+1) / 86400);
	}
}

class PostGroup extends ActiveRecord
{
  protected static $table = null;
  protected static $columns = null;
}

class PostCat extends ActiveRecord
{
  protected static $table = null;
  protected static $columns = null;
	protected static $queue = array();
	
	public static function Queue($postid)
	{
		self::$queue[$postid] = $postid;
	}
	
	public static function Update()
	{
		static::Init();
		if (count(self::$queue) > 0)
		{
			$query = 'SELECT "posts".*,GROUP_CONCAT("postgroup"."groupid") AS "groups" FROM "posts" LEFT JOIN "postgroup" ON ("postgroup"."postid" = "posts"."id") '
							.'WHERE "posts"."id" IN ('.implode(',',self::$queue).') GROUP BY "posts"."id"';
			$rs = static::$conn->Execute($query);
			while ($row = $rs->Fetch())
			{
				$status = true;
				// If last article was posted in the past hour and not yet complete = DELETE
				if (($row['post_date'] > (time() - 3600)) && ($row['parts_found'] != $row['parts_total']))
					$status = false;
				// If last article was posted in the past 10 minutes then post may not yet be complete = DELETE
				if ($row['post_date'] > (time() - 600))
					$status = false;
				// If hidden = DELETE
				if ($row['hidden'])
					$status = false;
				// If less than 80% complete = DELETE
				if ($row['parts_found'] < ((float)$row['parts_total'] * 0.8))
					$status = false;
				// If just a single file
				if ($row['files'] <= 1)
					$status = false;
				// If to small = DELETE
				if ($row['size'] < 1048576)
					$status = false;
				// If only PAR or PAR2 = DELETE
				if (!empty($row['stats']))
				{
					parse_str($row['stats'],$files);
					if (isset($files['par']))
						unset($files['par']);
					if (isset($files['par2']))
						unset($files['par2']);
					if (count($files) == 0)
						$status = false;
				}
				if ($status)
				{
					// Add or update
					$groups = explode(',',$row['groups']);
					$cats = array();
					foreach ($groups as $group)
						$cats[$GLOBALS['groupcat'][$group]] = $GLOBALS['groupcat'][$group];
					arsort($cats); // Latter categories are more important; Anime = least specific category
					$set_primary = true; // Last category (first in the array) will be set to primary
					foreach ($cats as $cat)
					{
						try {
							$postcat = PostCat::Find(null,'SELECT * FROM "postcat" WHERE ("postid" = ?) AND ("catid" = ?)',$row['id'],$cat);
						} catch (ActiveRecord_NotFoundException $e) {
							$postcat = new PostCat();
						}
						$postcat->catid = $cat;
						$postcat->postid = $row['id'];
						$postcat->primarycat = (int)$set_primary;
						$postcat->post_date = $row['post_date'];
						$postcat->updated = time();
						$postcat->Save();
						unset($postcat);
						if ($set_primary) // Disable flag to mark only first as primary
							$set_primary = false;
					}
				}
				else // Otherwise delete existing (if any)
				{
					static::$conn->Execute('DELETE FROM "postcat" WHERE "postid" = ?',$row['id']);
					// Remove cached NZB file too
					try {
						if (file_exists(Post::NZBFile($row['id'])))
							unlink(Post::NZBFile($row['id']));
					} catch (Exception $e) {
						// Ignore
					}
				}
			}
		}
	}
}

class Download extends ActiveRecord
{
  protected static $table = 'downloads';
  protected static $columns = null;

  public function Save()
  {
    if (is_null($this->_key))
      $this->created = time();
    parent::Save();
  }
}
?>
