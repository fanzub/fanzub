<?php // coding: latin1
/**
 * Standard Classes and Functions
 *
 * Copyright 2008-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id: class.standard.php 12 2012-04-26 13:58:59Z ghdpro $
 * @package Visei_Framework
 */

// Defines
define('YES',1);
define('NO',0);
define('ACTIVE',1);
define('INACTIVE',0);
define('DATE_NEVER',2147483647);

$page_start = GetMicroTime();

// PHP version check
if (!function_exists('version_compare'))
	throw new ErrorException('Function <code>version_compare</code> does not exist');
if (version_compare(phpversion(),'5.3.0') == -1)
 	throw new ErrorException('This site requires PHP 5.3.x or newer');

// No magic quotes
if (get_magic_quotes_gpc())
	throw new ErrorException('This site requires the <code>magic_quotes</code> PHP setting to be turned <u>off</u>');
	
// No register globals
if (ini_get('register_globals'))
	throw new ErrorException('This site requires the <code>register_globals</code> PHP setting to be turned <u>off</u>');

// Check for injection attack
$invalid_request_var = array('GLOBALS','_SERVER','HTTP_SERVER_VARS','_GET','HTTP_GET_VARS','_POST','HTTP_POST_VARS','_COOKIE','HTTP_COOKIE_VARS','_FILES','HTTP_POST_FILES','_ENV','HTTP_ENV_VARS','_REQUEST','_SESSION','HTTP_SESSION_VARS');
foreach ($_REQUEST as $key=>$value)
	if (in_array($key,$invalid_request_var))
	{
		header('HTTP/1.1 500 Internal Server Error');
		die('<h1>500 Internal Server Error</h1>Attempt to overwrite super-globals rejected.');
	}

// Unicode
if (!extension_loaded('mbstring'))
	throw new ErrorException('Multibyte String extension not found. Check PHP configuration');
mb_internal_encoding('UTF-8');

// Check for SQLite3 extension
if (!extension_loaded('sqlite3'))
	throw new ErrorException('SQLite3 extension not found. Check PHP configuration');

// Check for Memcache extension
if (!extension_loaded('memcache'))
	throw new ErrorException('Memcache extension not found. Check PHP configuration');
	
// Check for CURL extension
if (!extension_loaded('curl'))
	throw new ErrorException('CURL extension not found. Check PHP configuration');

// Auto-Install UncaughtExceptionHandler
set_exception_handler('DumpException');

// Auto-Install ExceptionErrorHandler
set_error_handler('ExceptionErrorHandler');

class CustomException extends Exception
{
	protected $custominfo = null;
	protected $customlabel = '';
	
	function getMessageText()
	{
		return htmlspecialchars_decode(strip_tags(str_replace(array('<i>','</i>','<b>','</b>','<code>','</code>'),'"',$this->getMessage())));
	}
	
	function getCustomInfo()
	{
		return $this->custominfo;
	}
	
	function getCustomLabel()
	{
		return $this->customlabel;
	}
}

// Database Exceptions
class DatabaseException extends CustomException
{
	public function __construct($message = null,$code = 0,$query = '')
	{
		parent::__construct($message,$code);
		if (!empty($query))
		{
			$this->custominfo = $query;
			$this->customlabel = 'Query';
		}
	}
}
class SQLException extends DatabaseException {}

class Journal
{
	const FATAL = 'fatal';
	const WARNING = 'warning';
	const NOTICE = 'notice';
	const REPEAT_TIMEOUT = 3600;
	const TIMEOUT = 10;
	const SQLITE_BUSY = 5;

	public static function Log($level,$class,$message,$details = '',$object = '')
	{
		// Logging is disabled if database is set to false
		if (isset($GLOBALS['config']['path']['journal']) && ($GLOBALS['config']['path']['journal'] === false))
			return;
		// Otherwise, database must exist
		if (!isset($GLOBALS['config']['path']['journal']) || empty($GLOBALS['config']['path']['journal']))
			throw new Exception('No journal database specified');
		if (!file_exists($GLOBALS['config']['path']['journal']))
			throw new Exception('Journal database not found');
		// Init database
		$conn = new SQLite3($GLOBALS['config']['path']['journal']);
		// Init values
		$values = array();
		$values['level'] = "'".$conn->escapeString($level)."'";
		$values['class'] = "'".$conn->escapeString($class)."'";
		if (isset($GLOBALS['config']['object']['session']))
			$values['userid'] = $GLOBALS['config']['object']['session']->GetUserID();
		else
			$values['userid'] = 0;
		if (isset($_SERVER['REMOTE_ADDR']))
			$values['userip'] = "'".$conn->escapeString($_SERVER['REMOTE_ADDR'])."'";
		else
			$values['userip'] = "''";
		$values['message'] = "'".$conn->escapeString($message)."'";
		$values['details'] = "'".$conn->escapeString(substr((string)$details,0,1024))."'";
		if (isset($_SERVER['REQUEST_URI']))
			$values['request'] = "'".$conn->escapeString($_SERVER['REQUEST_URI'])."'";
		else
			$values['request'] = "''";
		if (isset($_SERVER['SCRIPT_NAME']))
			$values['script'] = "'".$conn->escapeString($_SERVER['SCRIPT_NAME'])."'";
		elseif (isset($_SERVER['PHP_SELF']))
			$values['script'] = "'".$conn->escapeString($_SERVER['PHP_SELF'])."'";
		else
			$values['script'] = "''";
		if (is_array($object))
			$values['object'] = "'".$conn->escapeString(implode(',',$object))."'";
		else
			$values['object'] = "'".$conn->escapeString($object)."'";
		// Check repeats
		$query = 'SELECT id FROM journal WHERE ';
		$where = '';
		foreach($values as $field=>$value)
		{
			if (!empty($where))
				$where .= ' AND ';
			$where .= '('.$field.' = '.$value.')';
		}																				
		$where .= ' AND (date_added > '.(time()-self::REPEAT_TIMEOUT).')';
		$rs = $conn->query($query.$where);
		$row = $rs->fetchArray();
		if ($row !== false)
		{
			// Increase repeat counter
			$query = 'UPDATE journal ';
			$query .= 'SET repeats = repeats + 1,date_repeat = '.time().' ';
			$query .= 'WHERE id = '.$row['id'];
		}
		else
		{
			// Insert journal entry
			$values['id'] = 'NULL';
			$values['date_added'] = time();
			$fields = implode('`,`',array_keys($values));
			$data = implode(',',array_values($values));
			$query = 'INSERT INTO journal (`'.$fields.'`) VALUES('.$data.')';
		}
		// SQLite does not always honor its own busy timeout handler
		// So, if we get busy error we simply try again until we succeed or timeout ourselves
		$start = time();
		$retry = true;
		$tries = 1;
		while($retry)
		{
			try {
				$conn->exec($query);
				$retry = false;
			} catch (Exception $e) {
				// If busy then ignore it until timeout has lapsed. If not, throw exception again.
				if ($conn->lastErrorCode() != self::SQLITE_BUSY)
					throw $e;
				if ((time() - $start) > self::TIMEOUT)
					throw new DatabaseException('Unable to write to database; still locked after waiting '.self::TIMEOUT.' seconds',$conn->lastErrorCode(),$query);
				usleep(10000 * $tries); // 10ms times number of tries
				$tries++;
			}
		}
	}
}

class Cache extends Memcache
{
	const DEFAULT_EXPIRE   = 3600;

	protected $status = false;
	protected $name = '';

	public function __construct($server = 'localhost',$port = 11211)
	{
		$this->status = (isset($GLOBALS['config']['cache']['status']) ? $GLOBALS['config']['cache']['status'] : false);
		$this->name = (isset($GLOBALS['config']['cache']['name']) ? $GLOBALS['config']['cache']['name'] : '');
		if ($this->status)
			$this->status = $this->connect($server,$port);
	}
	
	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
      case 'status':
      case 'name':
        return $this->{$name};
      
			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
	
	protected function Key($module,$key)
	{
		return $this->name.'::'.$module.'::'.$key;
	}
	
	public function Get($module,$key)
	{
		if ($this->status)
			return parent::get($this->Key($module,$key));
		else
			return false;
	}
	
	public function Add($module,$key,$value,$expire = self::DEFAULT_EXPIRE)
	{
		if ($this->status)
			parent::add($this->Key($module,$key),$value,0,$expire);
	}

	public function Set($module,$key,$value,$expire = self::DEFAULT_EXPIRE)
	{
		if ($this->status)
			parent::set($this->Key($module,$key),$value,0,$expire);
	}
	
	public function Delete($module,$key)
	{
		if ($this->status)
			parent::delete($this->Key($module,$key));
	}

	public function Flush()
	{
		if ($this->status)
			parent::flush();
	}
	
	public function Stats()
	{
		if ($this->status)
			return parent::getStats();
		else
			return array();
	}
}

class CURL
{
	protected static $config = null;
	protected $connection = null;
	protected $options = array();
	protected $info = array();
	protected $headerblocks = array();
	protected $headers = '';
	protected $stream = '';
	protected $error = '';
	
	public function __construct()
	{
		if (is_null(static::$config) && isset($GLOBALS['config']))
			static::$config = $GLOBALS['config'];
		// Initialize configuration defaults
		$this->options['cookiejar'] = isset(static::$config['path']['data']) ? static::$config['path']['data'].'/cookie.txt' : '/tmp';
		$this->options['agent'] = isset(static::$config['curl']['agent']) ? static::$config['curl']['agent'] : 'Mozilla/4.0 (compatible; MSIE 8.0)';
		$this->options['bind'] = isset(static::$config['curl']['bind']) ? static::$config['curl']['bind'] : false;
		$this->options['referrer'] = isset(static::$config['curl']['referrer']) ? static::$config['curl']['referrer'] : '';
		$this->options['gzip'] = isset(static::$config['curl']['gzip']) ? static::$config['curl']['gzip'] : true;
		$this->options['timeout'] = isset(static::$config['curl']['timeout']) ? static::$config['curl']['timeout'] : 30;
		$this->options['follow'] = isset(static::$config['curl']['follow']) ? static::$config['curl']['follow'] : true;
	}

	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch(strtolower(trim($name)))
		{
			case 'info':
			case 'headerblocks':
			case 'headers':
			case 'stream':
			case 'error':
				return $this->{$name};
			  break;
			
			default:
				if (isset($this->info[$name]))
					return $this->info[$name];
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}

	/**
	 * Magic method for setting properties.
   */ 
	public function __set($name,$value)
	{
		switch(strtolower(trim($name)))
		{
			case 'cookiejar':
			case 'agent':
			case 'bind':
			case 'referrer':
			case 'gzip':
			case 'timeout':
			case 'follow':
				$this->options[$name] = $value;
				break;
			
			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
	
	/**
	 * Magic method for getting status of properties.
	 */ 
	public function __isset($name)
	{
		return isset($this->info[$name]);
	}
	
	public function Get($url,$post = null)
	{
		// Reset
		$this->info = array();
		$this->headerblocks = array();
		$this->headers = '';
		$this->stream = '';
		$this->error = '';
		// Init
		$this->connection = curl_init();
		if (!$this->connection)
			throw new Exception('Failed to initialize CURL');
		// Options
		$options = array(CURLOPT_URL => $url,
										 CURLOPT_HEADER => true,
										 CURLOPT_RETURNTRANSFER => true,
										 CURLOPT_BINARYTRANSFER => true,
										 CURLOPT_TIMEOUT => $this->options['timeout'],
										 CURLOPT_FOLLOWLOCATION => $this->options['follow']);
		if (!empty($this->options['cookiejar']))
		{
			$options[CURLOPT_COOKIEFILE] = $this->options['cookiejar'];
			$options[CURLOPT_COOKIEJAR] = $this->options['cookiejar'];
		}
		if (is_array($this->options['agent']))
			$options[CURLOPT_USERAGENT] = $this->options['agent'][mt_rand(0,count($this->options['agent'])-1)];
		else
			$options[CURLOPT_USERAGENT] = $this->options['agent'];
		if (is_array($this->options['bind']))
			$options[CURLOPT_INTERFACE] = $this->options['bind'][mt_rand(0,count($this->options['bind'])-1)];
		elseif (!empty($this->options['bind']))
			$options[CURLOPT_INTERFACE] = $this->options['bind'];
		if ($this->options['gzip'])
			$options[CURLOPT_HTTPHEADER][] = 'Accept-Encoding: gzip';
		if (!empty($this->options['referrer']))
			$options[CURLOPT_REFERER] = $this->options['referrer'];
		if (!is_null($post))
		{
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $post;
			$options[CURLOPT_HTTPHEADER][] = 'Expect: '; // lighttpd doesn't "expect" this header
		}
		$result = curl_setopt_array($this->connection,$options);
		if (!$result)
			throw new Exception('Failed to set CURL options');
		// Execute
		$result = curl_exec($this->connection);
		$this->info = curl_getinfo($this->connection);
		$this->error = curl_error($this->connection);
		curl_close($this->connection);
		// Processing
		$this->headerblocks = explode("\n\n",str_replace("\r",'',substr($result,0,$this->info['header_size'])));
		foreach($this->headerblocks as $i=>$block)
			if (empty($block))
				unset($this->headerblocks[$i]);
		if (count($this->headerblocks) > 0)
			$this->headers = end($this->headerblocks);
		$this->stream = substr($result,$this->info['header_size']);
		if (stristr($this->headers,'Content-Encoding: gzip'))
		{
			try {
				$this->stream = gzinflate(substr($this->stream,10));
			} catch (Exception $e) {
				$this->stream = '';
				$this->error = $e->getMessageText();
			}
		}
		return $this->stream;
	}
	
	public function Hammer($url,$attempts = 3)
	{
		// Only works for HTTP, will only attempt once otherwise
		for ($i = 0; $i < $attempts; $i++)
		{
			$this->Get($url);
			if (!isset($this->info['http_code']) || ($this->info['http_code'] > 0))
				return $this->stream;
			sleep(5);
		}
		return '';
	}
}

abstract class Cron
{
  protected static $config = null;
  protected static $conn = null;
  protected static $cache = null;
  protected $time = array();

  public function __construct()
  {
    if (is_null(static::$config) && isset($GLOBALS['config']))
      static::$config = $GLOBALS['config'];
    if (is_null(static::$conn) && isset($GLOBALS['conn']))
      static::$conn = $GLOBALS['conn'];
    if (is_null(static::$cache) && isset($GLOBALS['cache']))
      static::$cache = $GLOBALS['cache'];
    $this->time['start'] = GetMicroTime();
    $this->Limits();
  }
  
  public function __destruct()
  {
    echo $this->Stats();
  }
  
  protected function Limits($memory = '256M',$time = 600)
  {
    ini_set('memory_limit',$memory);
    set_time_limit($time);
  }
  
  protected function Title($title)
  {
    return '<h2>Cron '.$title.'</h2><p><i>'.date('r').'</i></p>'."\n";
  }
  
  protected function Stats()
  {
    $this->time['end'] = GetMicroTime();
    $duration = $this->time['end'] - $this->time['start'];
    return '<p><i>Statistics</i><br />'."\n"
          .'Total time: <b>'.number_format($duration,2).'</b> seconds ('
          .'php: '.number_format(abs($duration-static::$conn->duration),3).'s - '
          .'memory: '.number_format(memory_get_peak_usage()/1048576,1).' MiB - '
          .'sql: '.number_format(static::$conn->duration,3).'s / '.static::$conn->count." queries)</p>\n";
  }
  
  public abstract function Run();
}

abstract class Worker extends Cron
{
	const HIGH_PRIORITY	  = 0;
	const MEDIUM_PRIORITY = 1;
	const LOW_PRIORITY    = 2;
	
	const TIMEOUT					= 900;

	protected $beanstalk = null;
	protected $job = null;
	
	public function __construct()
	{
		parent::__construct();
		if (!isset(static::$config['beanstalk']))
			throw new ErrorException('Beanstalk server not configured');
		$this->beanstalk = Beanstalk::open(static::$config['beanstalk']);
	}
	
	public function WatchTube($tube)
	{
		$this->beanstalk->watch($tube);
	}
	
	public function ReserveJob($tube)
	{
	  $this->WatchTube($tube);
	  $this->job = $this->beanstalk->reserve();
	  if (!BeanQueueJob::check($this->job))
	    throw new ErrorException('Beanstalk returned an invalid job');
		$loadavg = LoadAverage();
		if (isset(static::$config['cron']['loadlimit']) && ($loadavg !== false) && isset($loadavg[1]) && ($loadavg[1] > static::$config['cron']['loadlimit']))
		{
			$this->ReleaseJob();
			sleep(1); // Prevent worker from being re-run again to quickly
			die('<b>Task aborted</b>: load average '.$loadavg[1].' exceeds threshold');
		}
	}
	
	public function ReleaseJob($delay = 1,$priority = Worker::MEDIUM_PRIORITY)
	{
		if (!is_null($this->job) && BeanQueueJob::check($this->job))
		{
			$this->job->release($priority,$delay);
			$this->job = null; // Job is no longer valid
		}
	}
	
	public function DeleteJob()
	{
		if (!is_null($this->job) && BeanQueueJob::check($this->job))
		{
			Beanstalk::delete($this->job);
			$this->job = null; // Job is no longer valid
		}
	}
	
	public static function PutJob($tube,$data,$delay = 0,$priority = Worker::MEDIUM_PRIORITY,$timeout = Worker::TIMEOUT)
	{
		static $beanstalk = null;
		if (is_null($beanstalk))
			$beanstalk = Beanstalk::open($GLOBALS['config']['beanstalk']);
		static $tube_used = null;
		if ($tube_used != $tube)
		{
			$beanstalk->use_tube($tube);
			$tube_used = $tube;
		}
		$beanstalk->put($priority,$delay,$timeout,$data);
	}
}

class Month
{
	/**
	 * Gets name of month by number (1 - 12)
	 */ 
	public static function Name($number)
	{
		static $monthname = array( 1 => 'January',
															 2 => 'February',
															 3 => 'March',
															 4 => 'April',
															 5 => 'May',
															 6 => 'June',
															 7 => 'July',
															 8 => 'August',
															 9 => 'September',
															 10 => 'October',
															 11 => 'November',
															 12 => 'December');
		if (isset($monthname[$number]))
			return $monthname[$number];
		else
			return 'Undefined';
	}
	
	/**
	 * Gets name of month in lowercase
	 */ 
	public static function LCase($number)
	{
		return strtolower(self::Name($number));
	}
	
	/**
	 * Gets number of month by name or number. Only checks first 3 characters so both abbreviated and full month names allowed.
	 */ 
	public static function Lookup($value)
	{
		switch (substr(strtolower(trim($value)),0,3))
		{
			case '1':
			case 'jan':
				return 1;
			
			case '2':
			case 'feb':
				return 2;

			case '3':
			case 'mar':
				return 3;

			case '4':
			case 'apr':
				return 4;

			case '5':
			case 'may':
				return 5;

			case '6':
			case 'jun':
				return 6;

			case '7':
			case 'jul':
				return 7;

			case '8':
			case 'aug':
				return 8;

			case '9':
			case 'sep':
				return 9;

			case '10':
			case 'oct':
				return 10;

			case '11':
			case 'nov':
				return 11;

			case '12':
			case 'dec':
				return 12;
			
			default:
				return false;
		}
	}
}

class Base62
{
  const BASE = 62;
  const CHARS = '3Wb4NrqxEKs0UoGcelYFyRJdpwOMT2IkLnfZh1XHjSmBQ7DVtA98uCavPg65zi';
  
  public static function Encode($number,$chars = self::CHARS)
  {
    $result = '';
    while($number >= self::BASE)
    {
      $r = $number % self::BASE;
      $result = $chars[$r].$result;
      $number = $number / self::BASE;
    }
    return $chars[$number].$result;
  }
  
  public static function Decode($string,$chars = self::CHARS)
  {
    $result = 0;
    for ($i = 0; $i<strlen($string); $i++)
    {
      $value = strpos($chars,$string[$i]);
      $number = $value * pow(self::BASE,strlen($string)-($i+1));
      $result += $number;
    }
    return $result;
  }
  
  public static function BadWord($string)
  {
    $badwords = array('arse','ass','anu',
                      'but','bitc','blo','bone','bum',
                      'cun','coc','crap','cum','cli',
                      'die','drug','dam','dic','dild',
                      'eja',
                      'fuck','fcuk','fuk','fel','fag','fart','fap','fae',
                      'gay','god',
                      'hus','hom','hel','hol',
                      'jac','jis','jiz','jap','jerk',
                      'kil','kum','koc',
                      'loli','l0li','lesb',
                      'muf',
                      'nig',
                      'org',
                      'pis','porn','p0rn','pus','peni','ped','phu','pimp','pric',
                      'sex','shit','slut','smut','spun',
                      'tit','twat',
                      'urin',
                      'vulv',
                      'whor','womb',
                      'xxx');
    foreach ($badwords as $word)
      if (stripos($string,$word) !== false)
        return true;
    return false;
  }
}

function ExceptionErrorHandler($severity,$message,$filename,$line)
{
	if (error_reporting() == 0)
		return;
	if (error_reporting() & $severity)
	{
		if ($severity == E_NOTICE)
		{
			if (defined('DEBUG'))
				Journal::Log(Journal::NOTICE,'Notice',$message,$filename.':'.$line);
		}
		else
			throw new ErrorException($message,0,$severity,$filename,$line);
	}
}

function DumpException($exception)
{
	// Basic information
	$header = '<br /><b>'.get_class($exception).':</b> '."\n";
	$message = $exception->getMessage().' ';
	if ($exception->getCode() != 0)
		$message .= '(<code>'.$exception->getCode().'</code>) '."\n";
	$footer = '';
	$file = $exception->getFile();
	if (!empty($file))
	{
		$footer .= 'in <b>'.$exception->getFile().'</b> '."\n";
		$footer .= 'on line <b>'.$exception->getLine().'</b>';
	}
	$footer .= '<br />'."\n";
	if (defined('DEBUG'))
		echo $header.$message.$footer;
	else
		echo '<br />An unhandled <b>'.get_class($exception).'</b> was thrown. Please contact an administrator if this problem persists.<br />'."\n";
	// Debug information
	$details = '<p>';
	if (method_exists($exception,'getCustomLabel'))
	{
		$customlabel = $exception->getCustomLabel();
		if (!empty($customlabel))
		{
			$details .= '<i>'.$customlabel.'</i><br />'."\n\n";
			$details .= '<pre>';
			$details .= $exception->getCustomInfo();
			$details .= '</pre>'."\n";
		}
	}
	$details .= '<i>Trace</i><br />'."\n\n";
	$details .= '<pre>';
	$details .= $exception->getTraceAsString();
	$details .= '</pre>'."\n";
	$details .= '</p>';
	if (defined('DEBUG'))
		echo $details;
	// Log exception
	$object = '';
	if (!empty($file))
		$object = $exception->getFile().':'.$exception->getLine();
	try {
		Journal::Log(Journal::FATAL,get_class($exception),trim(strip_tags($message)),trim(strip_tags($details)),$object);
	} catch (Exception $e) {
		die('<br /><b>Error:</b> '.$e->getMessage()).'<br />'."\n";
	}
}

function GetMicroTime()
{ 
	list($usec,$sec) = explode(' ',microtime());
	return ((float)$usec + (float)$sec);
}

function SafeHTML($string)
{
	return htmlspecialchars(FixUnicode($string),ENT_COMPAT,'UTF-8',false);
}

function FixUnicode($string)
{
	if ((mb_detect_encoding($string) == 'UTF-8') && mb_check_encoding($string,'UTF-8'))
		return $string;
	else
		return utf8_encode($string);
}

function FixURL($url)
{	
	$url = rawurldecode($url); // Prevent double escaping
	$url = str_replace(array('&amp;','&#38;'),'&',$url); // Decode "&" characters
	$parts = @parse_url($url);
	$result = ''; // Re-assemble URL
	if (isset($parts['scheme']) && !empty($parts['scheme']))
		$result .= rawurlencode($parts['scheme']).'://';
	if (isset($parts['user']) && !empty($parts['user']))
		$result .= rawurlencode($parts['user']);
	if (isset($parts['pass']) && !empty($parts['pass']))
		$result .= ':'.rawurlencode($parts['pass']);
	if (isset($parts['user']) && !empty($parts['user']))
		$result .= '@';
	if (isset($parts['host']) && !empty($parts['host']))
		$result .= rawurlencode($parts['host']);
	if (isset($parts['port']) && !empty($parts['port']))
		$result .= ':'.intval($parts['port']);
	if (isset($parts['path']) && !empty($parts['path']))
		$result .= str_replace(array('%2F','%7E'),array('/','~'),rawurlencode($parts['path'])); // Some webservers prefer "~" over %7E
	if (isset($parts['query']) && !empty($parts['query']))
		$result .= '?'.str_replace(array('%26','%3D'),array('&','='),rawurlencode($parts['query'])); // Be careful with url encoding query string
	return $result;
}

function ForceWrap($string)
{
	// Cut large contineous strings in chunks using zero-width spaces
	$string = preg_replace_callback('/([a-zA-Z0-9]{20,})/i',function($value) { return chunk_split($value[0],10,mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES')); },$string);
	// Add zero-width spaces after (or before) certain characters
  return str_replace(array('_',
													 '-',
													 '.',
													 '=',
													 ']',
													 ')',
													 '}',
													 '%'
													),
										 array('_'.mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES'),
													 '-'.mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES'),
													 mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES').'.',
													 '='.mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES'),
													 ']'.mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES'),
													 ')'.mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES'),
													 '}'.mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES'),
													 mb_convert_encoding('&#8203;','UTF-8','HTML-ENTITIES').'%'
													),
										 $string);
}

function boolval($bool)
{
	switch((string)strtolower(trim($bool)))
	{
		case 't':
		case 'true':
		case 'y':
		case 'yes':
		case 'on':
		case '1':
			return true;
	}
	return false;
}

function FormatSize($size,$decimals = 1)
{
	if ($size < 1024)
		return $size.' B';
	elseif ($size < 1048576)
		return ceil($size / 1024).' KiB';
	elseif ($size < 1073741824)
		return number_format(floatval($size) / 1048576,$decimals).' MiB';
	else
		return number_format(floatval($size) / 1073741824,$decimals).' GiB';
}

function DetectProxy()
{
	$result = '';
	if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP']))
		$result = $_SERVER['HTTP_CLIENT_IP'];
	elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s',$_SERVER['HTTP_X_FORWARDED_FOR'],$matches))
	{
		foreach ($matches[0] as $ip)
		{
	 		// Ignore internal IPs
			if (!preg_match('#^(10|172\.16|192\.168)\.#', $ip))
				$result = $ip;
		}
	}
	elseif (isset($_SERVER['HTTP_FROM']) && !empty($_SERVER['HTTP_FROM']))
		$result = $_SERVER['HTTP_FROM'];
	return $result;
}

function LoadAverage()
{
	if (file_exists('/proc/loadavg'))
	{
		$result = array();
		$data = file_get_contents('/proc/loadavg');
		$parts = explode(' ',$data);
		if (isset($parts[0]))
			$result[0] = floatval($parts[0]);
		if (isset($parts[1]))
			$result[1] = floatval($parts[1]);
		if (isset($parts[2]))
			$result[2] = floatval($parts[2]);
		return $result;
	}
	else
		return false;
}

function Paginator($url,$value,$max,$suffix = '')
{
	$result = '<div class="paginator">';
	// <<
	if ($value > 1)
		$result .= '<a href="'.$url.($value-1).$suffix.'" class="arrow" title="Previous">&lt;&lt;</a>';
	else
		$result .= '<span class="inactive" title="Previous">&lt;&lt;</span>';
	// Previous
	if ($value > 1)
	{
		if ($value > 5)
		{
			$result .= ' <a href="'.$url.'1'.$suffix.'">1</a> ... ';
			for ($i = ($value-2); $i < $value; $i++)
				$result .= ' <a href="'.$url.$i.$suffix.'">'.$i.'</a> ';
		}
		else
		{
			for ($i = 1; $i < $value; $i++)
				$result .= ' <a href="'.$url.$i.$suffix.'">'.$i.'</a> ';
		}
	}
	// Current
	if ($max > 1)
		$result .= ' <span class="current">'.$value.'</span> ';
	// Next
	if ($value < $max)
	{
		if ($value < ($max-4))
		{
			for ($i = ($value+1); $i < ($value+3); $i++)
				$result .= ' <a href="'.$url.$i.$suffix.'">'.$i.'</a> ';
			$result .= ' ... <a href="'.$url.$max.$suffix.'">'.$max.'</a> ';
		}
		else
		{
			for ($i = ($value+1); $i <= $max; $i++)
				$result .= ' <a href="'.$url.$i.$suffix.'">'.$i.'</a> ';
		}
	}
	// >>
	if ($value < $max)
		$result .= '<a href="'.$url.($value+1).$suffix.'" class="arrow" title="Next">&gt;&gt;</a>';
	else
		$result .= '<span class="inactive" title="Next">&gt;&gt;</span>';
	$result .= '</div>'."\n";
	return $result;
}
?>