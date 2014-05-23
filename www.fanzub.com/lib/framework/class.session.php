<?php // coding: latin1
/*
 * Session Class
 *
 * Copyright 2009-2010 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id: class.session.php 4 2011-12-18 12:22:42Z ghdpro $
 * @package Visei_Framework
 */

class Session extends ActiveRecord
{
  const COOKIE              = 'session';
  const TIMEOUT     				= 1800; // 30 minutes
	const MEMCACHE_KEY				= 'session';
  const BRUTEFORCE_ATTEMPTS = 5;
  const BRUTEFORCE_TIMEOUT  = 300; // 5 minutes
	const LASTVISIT_COOKIE 		= 'lastvisit';
	const LASTVISIT_TIMEOUT		= 7776000; // 90 days
  
	protected static $config = null;
  protected static $table = null;
  protected static $columns = null;
  public $user = null;
  protected $dtz = null;
	protected static $is_member = null;
	protected static $is_staff = null;
	protected static $is_admin = null;

  /**
   * Session constructor
   */ 
  public function __construct()
  {
    parent::__construct();
		if (is_null(static::$config) && isset($GLOBALS['config']))
			static::$config = $GLOBALS['config'];
    // User information
		if ((php_sapi_name() != 'cli') && !isset($_SERVER['REMOTE_ADDR']))
			throw new ErrorException('Unable to determine your IP address.');
    $this->userip = DetectProxy();
    if (empty($this->userip))
      $this->userip = $_SERVER['REMOTE_ADDR'];
    $this->useragent = (isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'],0,100) : '');
    // Filter out long numbers (4+ digits)
    $this->useragent = preg_replace('/([0-9]{4,12})/','',$this->useragent);
    // Filter out version except major version number
    $this->useragent = preg_replace('/([0-9]{1,10})([\._-])?([0-9]{1,10})?([\._-])?([0-9]{1,10})?([\._-])?([0-9]{1,10})?([\._-])?([0-9]{1,10})?/','$1',$this->useragent);
  }

	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
			default:
				if (isset($this->changes[$name]))
					return $this->changes[$name];
        elseif (isset($this->fields[$name]))
          return $this->fields[$name];
        elseif (isset(static::$columns[$name]))
          return null;
        elseif (!is_null($this->user) && isset($this->user->{$name}))
          return $this->user->{$name};
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}

	/**
	 * Magic method for getting status of properties.
	 */ 
	public function __isset($name)
	{
		return (parent::__isset($name) || (!is_null($this->user) && isset($this->user->{$name})));
	}
  
  protected function MatchIP($ip1,$ip2,$bits)
  {
    $ip1 = ip2long($ip1);
    $ip2 = ip2long($ip2);
    $bits = -1 << (32 - $bits);
    return (($ip1 & $bits) == ($ip2 & $bits));
  }
  
  public function Load(array $row = null)
  {
		$parts = explode('-',$_COOKIE[Session::COOKIE]);
		if (isset($parts[1]))
		{
			// Try memcache first (guest & member)
			if (!is_null(static::$cache))
				$row = static::$cache->Get(Session::MEMCACHE_KEY,$parts[1]);
			// Try database second (members only)
			if (($row == false) && boolval($parts[0]))
			{
				try {
					$query = 'SELECT "session".*,"users".* FROM "session" '
									.'LEFT JOIN "users" ON ("session"."userid" = "users"."id") '
									.'WHERE "session"."hash" = ?';
					$rs = static::$conn->Execute($query,$parts[1]);
					$row = $rs->Fetch();
					// Only set ActiveRecord primary key when loading from database (otherwise Save() will try to do UPDATE on non-existing record)
					$this->_key['hash'] = $row['hash'];
				} catch (SQLException $e) {
					$row = false;
				}
			}
			// Load values
			if ($row != false)
			{
				// Load session
				foreach (static::$columns as $name=>$value)
					if (isset($row[$name]))
						$this->fields[$name] = $row[$name];
				// Load user
				if (isset($row['id']))
				{
					$this->user = new User();
					$this->user->Load($row);
				}
				else
					$this->user = null;
			}
		}
  }

  public function Save()
  {
    $this->last_activity = time();
    // Generate hash if cookie doesn't exist (obviously) or if userid has changed
    if (!isset($_COOKIE[Session::COOKIE]) || isset($this->changes['userid']))
    {
			// Update last visited time
			if (!$this->last_visit)
			{
				if (isset($_COOKIE[Session::LASTVISIT_COOKIE]))
					$this->last_visit = intval($_COOKIE[Session::LASTVISIT_COOKIE]);
				else
					$this->last_visit = time();
			}
			$_COOKIE[Session::LASTVISIT_COOKIE] = time();
			setcookie(Session::LASTVISIT_COOKIE,time(),(time()+Session::LASTVISIT_TIMEOUT),'/',static::$config['url']['domain'],false,false);
			// Generate hash & set session cookie
      $hash = hash('sha512',GetMicroTime().$this->userip.rand().rand().rand());
			$hash = hash_hmac('sha512',$hash,file_get_contents('/dev/urandom',null,null,0,4096));
      $this->hash = $hash;
      $_COOKIE[Session::COOKIE] = $hash;
      setcookie(Session::COOKIE,($this->userid ? 1 : 0).'-'.$hash,0,'/',static::$config['url']['domain'],false,true);
			// Store member session in database
			if ($this->userid > 0)
				parent::Save();
    }
		// Store session in memcache
		if (!is_null(static::$cache))
		{
			$values = array_merge($this->fields,$this->changes);
			if (!is_null($this->user))
				$values = array_merge($values,$this->user->fields);
			static::$cache->Set(Session::MEMCACHE_KEY,$this->hash,$values,Session::TIMEOUT);
		}
  }
	
	/**
	 * Updates session table from memcached.
	 */ 
	public static function Update()
	{
		static::Init();
		if (!is_null(static::$cache))
		{
			$query = 'SELECT "hash" FROM "session"';
			$rs = static::$conn->Execute($query);
			while ($row = $rs->Fetch())
			{
				$cache = static::$cache->Get(Session::MEMCACHE_KEY,$row['hash']);
				if ($cache != false)
				{
					// Last activity is the only important value (determines session time-out)
					$values['last_activity'] = $cache['last_activity'];
					static::$conn->AutoUpdate('session',$values,'"hash" = ?',$row['hash']);
				}
			}
		}
	}
  
  public static function Delete($param)
  {
		// Depending on whether session was loaded from memcache or database, $param->_key (primary key) may or may not be set, so use $param->hash instead
    parent::Delete($param->hash);
    if (isset($_COOKIE[Session::COOKIE]))
      unset($_COOKIE[Session::COOKIE]);
    setcookie(Session::COOKIE,'-',0,'/',static::$config['url']['domain'],false,true);
		static::$cache->Delete(Session::MEMCACHE_KEY,$param->hash);
    $param->user = null;
  }
  
  public static function CleanUp()
  {
		static::Init();
    $query = 'DELETE FROM "'.static::$table.'" WHERE "last_activity" < ?';
    static::$conn->Execute($query,(time()-self::TIMEOUT));
  }
  
  public function Start()
  {
    // Try to login using existing session
    if (isset($_COOKIE[Session::COOKIE]))
    {
      $this->Load();
      if (!empty($this->hash))
      {
        // First two parts of IP and user agent string must match and session must not have expired
        if (!$this->MatchIP($this->fields['userip'],$this->changes['userip'],16) ||
            ($this->fields['useragent'] != $this->changes['useragent']) ||
            ($this->fields['last_activity'] < (time() - self::TIMEOUT))
            )
        {
          // No match = Force logout + Session hash change (as userid is changed)
          $this->userid = 0;
          $this->user = null;
					static::$is_member = null;
					static::$is_staff = null;
					static::$is_admin = null;
        }
      }
      else // Session not found in database, so make sure sessionhash is regenerated
        unset($_COOKIE[Session::COOKIE]);
    }
    // Try to login using session token
    if (!$this->IsLoggedIn() && isset($_COOKIE[Token::COOKIE]))
    {
      try {
        $token = Token::FindByHash($_COOKIE[Token::COOKIE]);
        $staff = array_merge(static::$config['usergroup']['staff'],static::$config['usergroup']['admin']);
        // Check if token has expired (for staff IP range and browser must also match)
        if (!in_array($token->userid,$staff) && ($token->created < (time() - Token::TTL)))
          Token::Delete($token);
        elseif (in_array($token->userid,$staff) && (($token->created < (time() - Token::TTL)) ||
               !$this->MatchIP($this->userip,$token->userip,16) ||
               ($this->useragent != $token->useragent))
                )
          Token::Delete($token);
        else
        {
          try {
            // Find & Update user
            $this->user = User::FindByID($token->userid);
            $this->user->last_login = time();
            $this->user->Save();
            // Verify user
            if (!$this->IsMember())
            {
              Token::Delete($token);
              $this->user = null;
            }
            else
            {
              // Update session
              $this->userid = $this->user->id;
              // Regenerate token hash and reset created time
              $token->Save();
            }
          } catch (ActiveRecord_NotFoundException $e) {
            Token::Delete($token);
            $this->user = null;
						static::$is_member = null;
						static::$is_staff = null;
						static::$is_admin = null;
          }
        }
      } catch (ActiveRecord_NotFoundException $e) {
        // Delete token on error (cookie mainly in this case)
        Token::Delete($_COOKIE[Token::COOKIE]);
      }
    }
    if ($this->IsLoggedIn() && !empty($this->timezone))
    {
      try {
        $this->dtz = new DateTimeZone($this->timezone);
      } catch (Exception $e) {
        // Ignore errors
      }
    }
		// Determine country code
		if (empty($this->usercc))
			$this->usercc = strtolower(trim(@geoip_country_code_by_name($this->userip)));
    // Save session
    $this->Save();
  }
  
  public function Date($format,$timestamp)
  {
		// Member (with valid timezone setting)
    if (!is_null($this->dtz))
    {
      $dt = new DateTime(date('r',$timestamp));
      $dt->setTimezone($this->dtz);
      return $dt->format($format);
    }
    // Guest user
    return date($format,$timestamp);
  }
  
  public function TimeOffset()
  {
    $offset = 0;
    // DateTimeZone object is created on the spot to make sure time is correct
    if ($this->IsLoggedIn() && isset($this->user->timezone) && !empty($this->user->timezone))
    {
      try {
        $dtz = new DateTimeZone($this->user->timezone);
        $offset = $dtz->getOffset(new DateTime('now'));
      } catch (Exception $e) {
        // Ignore errors
      }
    }
    return $offset;
  }
  
  public function Login($username,$password_md5,$password_length,$remember = false)
  {
    // Prevent brute force attacks
    $attempts = static::$cache->Get(Session::MEMCACHE_KEY,'brute_force_detector::'.rawurlencode($this->userip));
    if ($attempts === false)
      $attempts = array();
    foreach ($attempts as $key=>$value)
      if ($value < (time() - self::BRUTEFORCE_TIMEOUT))
        unset($attempts[$key]);
    $attempts[] = time();
    static::$cache->Set(Session::MEMCACHE_KEY,'brute_force_detector::'.rawurlencode($this->userip),$attempts);
    if (count($attempts) > self::BRUTEFORCE_ATTEMPTS)
      throw new Session_AccessViolationException('To many login attempts. Please wait '.round(self::BRUTEFORCE_TIMEOUT/60).' minutes and try again.');
		// Login
		$this->VerifyLogin($username,$password_md5,$password_length);
		$this->user->last_login = time();
		$this->user->Save();
		// Reload from database to get default values for custom fields in user table
		$this->user->Reload();
		// Verify user
		$this->AssertMember();
		// Save session
		$this->userid = $this->user->id;
		$this->Save();
		// Set token
		if ($remember)
		{
			$token = new Token();
			$token->userid = intval($this->user->id);
			$token->userip = $this->userip;
			$token->useragent = $this->useragent;
			$token->Save();
		}
  }
	
	protected function VerifyLogin($username,$password_md5,$password_length)
	{
		try {
			$this->user = User::FindByName($username);
		} catch (Exception $e) {
			// User not found
			throw new Session_AccessViolationException('Login failed: username and/or password are incorrect.');
		}
		// Verify password
		if (!$this->user->VerifyPassword($password_md5,$password_length))
		{
			$this->user = null;
			throw new Session_AccessViolationException('Login failed: username and/or password are incorrect.');
		}
	}
  
  public function Logout()
  {
    if (isset($_COOKIE[Token::COOKIE]))
      Token::Delete($_COOKIE[Token::COOKIE]);
    Session::Delete($this);
		static::$is_member = null;
		static::$is_staff = null;
		static::$is_admin = null;
  }
  
  public function IsLoggedIn()
  {
    return !is_null($this->user);
  }
  
  public function IsMember()
  {
		if (is_null(static::$is_member))
		{
	    try {
	      $this->AssertMember();
	      static::$is_member = true;
	    } catch (Session_AccessViolationException $e) {
	      static::$is_member = false;
	    }
		}
		return static::$is_member;
  }
  
  public function IsStaff()
  {
		if (is_null(static::$is_staff))
		{
	    try {
	      $this->AssertStaff();
	      static::$is_staff = true;
	    } catch (Session_AccessViolationException $e) {
	      static::$is_staff = false;
	    }
		}
		return static::$is_staff;
  }
  
  public function IsAdmin()
  {
		if (is_null(static::$is_admin))
		{
	    try {
	      $this->AssertAdmin();
	      static::$is_admin = true;
	    } catch (Session_AccessViolationException $e) {
	      static::$is_admin = false;
	    }
		}
		return static::$is_admin;
  }
  
  public function AssertMember()
  {
    if (!$this->IsLoggedIn() || !isset($this->user->usergroup))
      throw new Session_AccessViolationException('You are not logged in.');
    if ($this->user->usergroup == User::USERGROUP_BANNED)
      throw new Session_AccessViolationException('You are banned.');
    elseif ($this->user->usergroup == User::USERGROUP_UNCONFIRMED)
      throw new Session_AccessViolationException('You have not activated your account. Check your email for instructions.');
    elseif ($this->user->usergroup != User::USERGROUP_MEMBER)
      throw new Session_AccessViolationException('You are not a registered user.');
  }
  
  public function AssertStaff()
  {
    $this->AssertMember();
    $staff = array_merge(static::$config['usergroup']['staff'],static::$config['usergroup']['admin']);
    if (!in_array($this->user->id,$staff))
      throw new Session_AccessViolationException('Access denied: you do not have enough privileges to perform this action.');
  }

  public function AssertAdmin()
  {
    $this->AssertMember();
    if (!in_array($this->user->id,static::$config['usergroup']['admin']))
      throw new Session_AccessViolationException('Access denied: you do not have enough privileges to perform this action.');
  }
}

abstract class SessionUser extends ActiveRecord
{
	const MIN_USERNAME_LENGTH		= 3;
	const MAX_USERNAME_LENGTH		= 50;
	const MIN_PASSWORD_LENGTH		= 4;
	const MAX_PASSWORD_LENGTH 	= 30;
	const FLOOD_LIMIT						= 3;
	
  const USERGROUP_GUEST       = 0;
  const USERGROUP_MEMBER      = 1;
  const USERGROUP_BANNED      = 2;
  const USERGROUP_UNCONFIRMED = 3;

	/**
	 * Magic method for setting properties.
   */ 
	public function __set($name,$value)
	{
		switch($name)
		{
			case 'name':
				$value = trim($value);
				if (strlen($value) < self::MIN_USERNAME_LENGTH) // Usage of strlen() intentional for unicode characters
					throw new ErrorException('Username should be at least '.self::MIN_USERNAME_LENGTH.' characters.');
				if (mb_strlen($value) > self::MAX_USERNAME_LENGTH)
					throw new ErrorException('Username may not exceed '.self::MAX_USERNAME_LENGTH.' characters.');
				if (SessionUser::IllegalUsername($value))
					throw new ErrorException('Username is reserved. Please choose another username.');
				try {
					$class = get_called_class();
					$user = $class::FindByName($value);
					throw new ErrorException('Username already exists. Please choose another username.'); 
				} catch (ActiveRecord_NotFoundException $e) {
					// "Not Found" is the desired result
				}
        parent::__set($name,$value);
				break;

			case 'password':
				throw new ErrorException('Use <i>SetPassword()</i> to change password.');
				
			case 'email':
				if (!empty($value))
				{
					$value = filter_var($value,FILTER_VALIDATE_EMAIL);
					if ($value == false)
						throw new ErrorException('Email address is not valid. Please check if you entered it correctly.');
					// Check if email host actually exists (parse_url() is faked into thinking email address is URL to get hostname)
					$parts = parse_url('http://'.$value.'/');
					if (!isset($parts['host']) || ((filter_var($parts['host'],FILTER_VALIDATE_IP) == false) && (gethostbyname($parts['host']) == $parts['host'])))
						throw new ErrorException('Your email host <i>'.SafeHTML($parts['host']).'</i> does not appear to exist.');
					parent::__set($name,$value);
				}
				break;

			default:
        parent::__set($name,$value);
		}
	}
	
	/**
	 * Loads user record and fixes any values out of range
	 */ 
  public function Load(array $row)
  {
    parent::Load($row);
		// Run through validation in __set()
		$exceptions = array('name','password','password_changed');
    foreach (static::$columns as $name=>$value)
		{
			if (!in_array($name,$exceptions))
			{
				try {
					$this->{$name} = $this->fields[$name];
				} catch (Exception $e) {
					// Existing values that are now illegal (and throw exceptions) should be ignored
				}
			}
			// Discard any value that hasn't been fixed (and remains unchanged)
			if (isset($this->changes[$name]) && ($this->changes[$name] == $this->fields[$name]))
				unset($this->changes[$name]);
		}
  }
	
	protected function FloodLimit()
	{
		$class = get_called_class();
		$query = 'SELECT COUNT(*) AS total FROM "'.$class::$table.'" WHERE ("registered" > (UNIX_TIMESTAMP()-86400)) AND ("ip" = ?)';
		$rs = static::$conn->Execute($query,$this->ip);
		$row = $rs->Fetch();
		if ($row['total'] >= self::FLOOD_LIMIT)
			throw new ErrorException('Flood limit reached: in the past 24 hours <b>'.SafeHTML($row['total']).'</b> accounts have been registered from your IP (<b>'.SafeHTML($this->ip).'</b>).');
	}
	
	/**
	 * Save user record.
	 */ 
	public function Save()
	{
		// Flood limit is for new users only
		if (is_null($this->_key))
			$this->FloodLimit();
		parent::Save();
	}

	/**
	 * Obtains password MD5 hash and length from a form.
	 */
	public static function FormPassword($prefix = 'v_')
	{
		static::Init();
    if (!isset($_POST) || !is_array($_POST) || (count($_POST) == 0))
			throw new ErrorException('Assertion failed: form not submitted.');
		// Obtain values
    $password = '';
		$password_length = 0;
		$password_verify = '';
		foreach ($_POST as $name=>$value)
		{
			if (!empty($prefix) && (strtolower(substr($name,0,strlen($prefix))) == $prefix))
				$name = substr($name,strlen($prefix));
			switch ($name)
			{
				case 'password':
					if (empty($password) && !empty($value))
						$password = md5((isset(static::$config['session']['salt']) ? static::$config['session']['salt'] : '').$value);
					break;

				case 'password_length':
					$password_length = (int)$value;
					break;

				case 'password_md5':
					if (empty($password) && !empty($value))
						$password = $value;
					break;

				case 'password_verify':
					if (empty($password_verify) && !empty($value))
						$password_verify = md5((isset(static::$config['session']['salt']) ? static::$config['session']['salt'] : '').$value);
					break;

				case 'password_verify_md5':
					if (empty($password_verify) && !empty($value))
						$password_verify = $value;
					break;
			}
		}
		if ($password_length == 0)
			throw new ErrorException('Password can not be empty.');
		if ($password != $password_verify)
			throw new ErrorException('Passwords do not match.');
		return array('md5' => $password,'length' => $password_length);
	}
	
	/**
	 * Sets password as SHA-1 hash. Requires MD5 hash and length of password (for compatibility with client-side MD5 hashing).
	 * (This makes setting password using $object->password = 'password'; not possible)
	 */ 
	public function SetPassword($md5,$length)
	{
		$md5 = strtolower(trim($md5));
		if (!preg_match('/^[0-9a-f]{32}$/',$md5))
			throw new ErrorException('Assertion failed: invalid password.');
		if ($length < self::MIN_PASSWORD_LENGTH)
			throw new ErrorException('Password should be at least '.self::MIN_PASSWORD_LENGTH.' characters.');
		if ($length > self::MAX_PASSWORD_LENGTH)
			throw new ErrorException('Password can not exceed '.self::MAX_PASSWORD_LENGTH.' characters.');
		if (md5($this->name) == $md5)
			throw new ErrorException('Password can not be identical to your username.');
		if (md5($this->email) == $md5)
			throw new ErrorException('Password can not be identical to your email address.');
		$badpass = array('1234','12345','123456','1234567','12345678','123456789','1234567890',
										 '4321','54321','654321','7654321','87654321','987654321','0987654321',
										 '000000','111111','123123','112233','6969','696969','7777777',
										 'aaaaaa','abc123','a1b2c3','abcdef','access','angel','angels','asdf','asdfg','arsenal',
										 'blessed','blessing','blahblah','biteme','baseball','badboy','bond007','batman',
										 'christ','computer','cheese',
										 'dragon','default',
										 'eagle1',
										 'faith','freedom','fuckme','fuckyou','fuckyou1','football',
										 'grace','god',
										 'heaven','hello','hello1','helpme',
										 'iloveyou','iloveyou1','iloveyou2','internet','iwantu','ihavenopass',
										 'jesus','jesus1',
										 'killer',
										 'letmein','love','lovely','love123',
										 'master','mustang','monkey','money',
										 'ncc1701',
										 'pass','password','password1','password2','passw0rd','pussy','princess','private',
										 'qwerty','qwertyui',
										 'sunshine','single','shadow','starwars','secret','soccer','summer','superman',
										 'test','testing','test123','trustno1','temp','temptemp',
										 'welcome','whatever',
										 $this->name.'1',$this->name.'2',$this->name.'3',
										);
		foreach ($badpass as $pass)
			if ((md5($pass) == $md5) || (md5(strtoupper($pass)) == $md5) || (md5(ucfirst($pass)) == $md5))
				throw new ErrorException('Password is to easy to guess. Please choose a more secure password.');
		$salt = mb_substr(hash('sha512',$GLOBALS['session']->userip.rand().GetMicroTime()),0,$length);
		$this->changes['password'] = $salt.mb_substr(hash('sha512',$salt.$md5),$length,64-$length);
		$this->password_changed = time();
	}
	
	/**
	 * Verifies password (through MD5 hash and length) against stored password.
	 */ 
	public function VerifyPassword($md5,$length)
	{
		$salt = mb_substr($this->password,0,$length);
		$password = $salt.mb_substr(hash('sha512',$salt.$md5),$length,64-$length);
		if ($this->password == $password)
			return true;
		return false;
	}
	
	/**
	 * Disallow certain usernames from being registered.
	 */ 
	public static function IllegalUsername($username)
	{
		$illegal = array('admin','admins','administrator','administrators','adminz','account','accounts','about','archive','archives','ads','advertising','alias','aliases',
										 'blog','blogs','board','boards',
										 'cache','caches',
										 'download','downloads','down',
										 'email','emails','e-mail','e-mails',
										 'ftp','ftps','forum','forums',
										 'glossary','god',
										 'home','help','host','hosts','hostmaster','html','http','https',
										 'irc','invite','invites','invitation','invitations','info',
										 'join','jobs','java','javascript',
										 'login','logout','log','logs','list','lists',
										 'mail','mails','moderator','mod','moderators','mods','moderate','master','member','members',
										 'news',
										 'option','options',
										 'public','private','profile','privacy','postmaster','proxy','proxies','pass','password',
										 'register','root',
										 'secure','ssl','signup','session','sessions','setting','settings','slave','server','servers','service','services',
										 'terms','tos','tracker','trackers','test','tests','testing',
										 'www','web','webmaster','wiki','wikis',
										 'upload','up','user','users','username','usernames',
										);
		if (in_array(strtolower(trim($username)),$illegal))
			return true;
		return false;
	}
}

class Token extends ActiveRecord
{
  const COOKIE = 'token';
  const TTL    = 1209600; // 2 weeks

  protected static $config = null;
  protected static $table = null;
  protected static $columns = null;
  
  protected static function Init()
  {
    parent::Init();
		if (is_null(static::$config) && isset($GLOBALS['config']))
			static::$config = $GLOBALS['config'];
  }
  
  public function Save()
  {
    $hash = hash('sha512',GetMicroTime().rand().rand().rand());
		$hash = hash_hmac('sha512',$hash,file_get_contents('/dev/urandom',null,null,0,4096));
    $this->hash = $hash;
    $_COOKIE[Token::COOKIE] = $hash;
    setcookie(Token::COOKIE,$hash,time() + Token::TTL,'/',static::$config['url']['domain'],false,true);
    $this->created = time();
    parent::Save();
  }
  
  public static function Delete($param)
  {
    parent::Delete($param);
    if (isset($_COOKIE[Token::COOKIE]))
      unset($_COOKIE[Token::COOKIE]);
    setcookie(Token::COOKIE,'-',time()-86400,'/',static::$config['url']['domain'],false,true);
  }

  public static function CleanUp()
  {
		static::Init();
    $query = 'DELETE FROM "'.static::$table.'" WHERE "created" < ?';
    static::$conn->Execute($query,(time()-self::TTL));
  }
}

class Session_AccessViolationException extends CustomException {}
?>