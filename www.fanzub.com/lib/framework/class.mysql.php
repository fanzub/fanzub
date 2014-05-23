<?php // coding: latin1
/**
 * MySQL Class
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id: class.mysql.php 6 2012-01-19 11:48:51Z ghdpro $
 * @package Visei_Framework
 */

// Check for MySQL Improved extension
if (!extension_loaded('mysqli'))
	throw new ErrorException('MySQL Improved extension not found. Check PHP configuration');
  
class MySQL extends mysqli
{
	const CACHEQUERY_TIMEOUT = 900;
	
  protected $connect = array();
  protected $connected = false;
  protected static $stats = array();
  
  public function __construct($server = 'localhost',$database = '',$username = '',$password = '',$port = '')
  {
    parent::init();
    $this->connect['server'] = $server;
    $this->connect['database'] = $database;
		$this->connect['schema'] = $database; // MySQL doesn't support schema's
    $this->connect['username'] = $username;
    $this->connect['password'] = $password;
		$this->connect['port'] = (empty($port) ? ini_get('mysqli.default_port') : $port);
		$this->connect['persistent'] = true;
		if (!isset(self::$stats['count']))
			self::$stats['count'] = 0;
		if (!isset(self::$stats['duration']))
			self::$stats['duration'] = (float)0;
  }
	
	public function __destruct()
	{
		if ($this->connected)
		{
			try {
				// Rollback any transaction that isn't commited yet (ie: after a fatal error)
				$this->Rollback();
			} catch (Exception $e) {
				// Ignore errors
			}
		}
	}

	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
			case 'count':
			case 'duration':
				return self::$stats[$name];

      case 'connected':
        return $this->connected;

			default:
				if (isset($this->connect[$name]) && ($name != 'password'))
					return $this->connect[$name];
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
	
	/**
	 * Magic method for setting properties.
   */ 
	public function __set($name,$value)
	{
		switch($name)
		{
			default:
        if (isset($this->connect[$name]))
          $this->connect[$name] = $value;
				else
					throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
  
  public function Open()
  {
    if (!$this->connected)
    {
      try {
        parent::real_connect((!is_null($this->connect['server']) && (bool)$this->connect['persistent'] ? 'p:'.$this->connect['server'] : $this->connect['server'] ),
														 $this->connect['username'],
														 $this->connect['password'],
														 $this->connect['database'],
														 $this->connect['port']);
				// Make sure persistent connection is still alive
				if (!parent::ping())
					throw new DatabaseException('Lost connection to database: unable to reconnect');
				// Enable Unicode
        parent::set_charset('utf8');
				// Enable double quotes for quoting identifiers instead of backticks (parent::query() used to avoid Execute() overhead)
				parent::query("SET sql_mode='ANSI_QUOTES'"); 
        unset($this->connect['password']);
        $this->connected = true;
      } catch (WarningException $e) {
  			if (mysqli_connect_errno())
  				throw new DatabaseException(mysqli_connect_error(),mysqli_connect_errno());
  			else
  				throw $e;
      }
    }
  }
  
	public function Escape($value)
	{
    if (!$this->connected)
      $this->Open();
		return $this->real_escape_string($value);
	}
  
  public function Execute($query)
  {
    if (!$this->connected)
      $this->Open();
		// Get extra arguments (for prepared statements)
		$args = array();
		if (func_num_args() > 1)
		{
			$args = func_get_args();
			if (isset($args[1]) && is_array($args[1]))
				$args = array_values($args[1]); // Second argument is array with values
			else
				array_shift($args); // Use all arguments as values (except query of course)
		}
	  $start = GetMicroTime();
		// Passed existing statement as first argument
		if (is_object($query) && ($query instanceof mysqli_stmt))
			$result = $this->ExecuteStatement($query,$args);
		// Prepared statement
		elseif ((strpos($query,'?') !== false) && (func_num_args() > 1) && (count($args) > 0))
		{
			$statement = parent::prepare($query);
			if ($this->errno)
				throw new SQLException($this->error,$this->errno,(string)$query);
			$result = $this->ExecuteStatement($statement,$args);
		}
		// Normal query
		else
		{
			$result = parent::query($query,MYSQLI_STORE_RESULT);
			if ($this->errno)
				throw new SQLException($this->error,$this->errno,(string)$query);
		}
		// Get meta data (for SELECT from prepared statment)
		if ($result instanceof mysqli_stmt)
		{
			$meta = $result->result_metadata();
			if ($this->errno)
				throw new SQLException($this->error,$this->errno,(string)$query);
		}
	  $end = GetMicroTime();
	  self::$stats['count']++;
	  self::$stats['duration'] += ($end - $start);
		// Return record set (if rows returned from query)
		if (is_object($result) && ($result instanceof mysqli_result))
			return new MySQLRecordSet($result);
		elseif (is_object($result) && ($result instanceof mysqli_stmt) && isset($meta) && is_object($meta))
			return new MySQLRecordSet($result,$meta);
		else
			return $result;
  }
	
	protected function ExecuteStatement(&$statement,$args)
	{
		// Reset
		$statement->reset();
		if ($this->errno)
			throw new SQLException($this->error,$this->errno);
		// Rebuild arguments list as references
		$values = array();
		foreach ($args as $key=>$value)
			$values[$key] = &$args[$key];
		// Build list of types
		$types = '';
		foreach ($args as $arg)
			if (is_int($arg))
				$types .= 'i';
			elseif (is_float($arg))
				$types .= 'd';
			else
				$types .= 's';
		array_unshift($values,$types);
		// Bind result
		call_user_func_array(array($statement,'bind_param'),$values);
		if ($this->errno)
			throw new SQLException($this->error,$this->errno);
		// Execute
		$statement->execute();
		if ($this->errno)
			throw new SQLException($this->error,$this->errno);
		// Store result
		$statement->store_result();
		if ($this->errno)
			throw new SQLException($this->error,$this->errno);
		return $statement;
	}

	public function CacheQuery($query,array $values = null,$expire = self::CACHEQUERY_TIMEOUT,$override = false)
	{
		$row = false;
		if ($override || !isset($GLOBALS['cache']) || (($row = $GLOBALS['cache']->Get('querycache',md5($query))) == false))
		{
			if (!is_null($values) && is_array($values))
				$rs = $this->Execute($query,$values);
			else
				$rs = $this->Execute($query);
			if (($row = $rs->Fetch()) && isset($GLOBALS['cache']))
				$GLOBALS['cache']->Set('querycache',md5($query),$row,$expire);
		}
		return $row;
	}
	
  public function AutoInsert($table,array $values,$id = null)
  {
		$fields = implode('","',array_keys($values));
		$query = 'INSERT INTO "'.$table.'" ("'.$fields.'") VALUES('.implode(',',array_fill(0,count($values),'?')).')';
		$this->Execute($query,$values);
		return $this->insert_id;
  }
  
  public function AutoUpdate($table,array $values,$where)
  {
		$query = 'UPDATE "'.$table.'" SET ';
		$fields = array();
		foreach ($values as $key=>$value)
			$fields[] = '"'.$key.'" = ?';
		$query .= implode(', ',$fields);
		$query .= ' WHERE '.$where;
		// Get where arguments (for prepared statements)
		$args = array();
		if (func_num_args() > 3)
		{
			$args = func_get_args();
			if (isset($args[3]) && is_array($args[3]))
				$args = $args[3]; // Fourth argument is array with values
			else
				$args = array_slice($args,3); // Use all arguments as values (except for table, values & where of course)
		}
		$values = array_merge(array_values($values),array_values($args));
		return $this->Execute($query,$values);
  }
	
	public function BeginTransaction()
	{
		return $this->Execute('START TRANSACTION');
	}
	
	public function Commit()
	{
		return $this->Execute('COMMIT');
	}
	
	public function Rollback()
	{
		return $this->Execute('ROLLBACK');
	}
	
	/**
	 * Takes Unix timestamp and returns format suitable for database. Returns current time if no timestamp given.
	 */
	public function Time($timestamp = null)
	{
		static $now = null;
		if (is_null($timestamp))
		{
			if (is_null($now))
				$now = time();
			return $now;
		}
		return $timestamp;
	}

	/**
	 * Takes time field from database and returns Unix timestamp.
	 */ 
	public function UnixTime($time)
	{
		return $time;
	}
	
	/**
	 * Returns formatted field name for WHERE
	 */
	public function WhereField($name,$is_text = false)
	{
		return '"'.$name.'" = ?';
	}
	
	/**
	 * Takes expression and returns boolean format suitable for database.
	 */
	public function Bool($expr)
	{
		return (int)boolval($expr);
	}
	
	/**
	 * Returns properly formatted LIMIT clause.
	 */
	public function Limit($limit,$offset = 0)
	{
		return ' LIMIT '.intval($offset).','.intval($limit);
	}
}

class MySQLRecordSet
{
	protected $result = null;
	protected $row = array(); // Only used for prepared statements
	
  public function __construct(&$result,$meta = false)
  {
		$this->result =& $result;
		if (($result instanceof mysqli_stmt) && ($meta !== false) && is_object($meta))
		{
			$fields = mysqli_fetch_fields($meta);
			$values = array();
			foreach ($fields as $field)
				$values[] = &$this->row[$field->name];
			call_user_func_array(array($this->result,'bind_result'),$values);
			if ($this->result->errno)
				throw new SQLException($this->result->error,$this->result->errno);
			mysqli_free_result($meta);
		}
  }
  
  public function __destruct()
  {
		// Only close result sets, NOT statements upon destruction (otherwise re-using statment is impossible)
		if ($this->result instanceof mysqli_result)
			$this->Close();
  }

	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
			case 'affected_rows':
			case 'num_rows':
				return $this->result->{$name};
			
			case 'statement':
				if ($this->result instanceof mysqli_stmt)
					return $this->result;

			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
  
  public function Close()
  {
    try {
			if (is_object($this->result))
				$this->result->close();
    } catch (Exception $e) {
    }
  }
  
  public function Fetch()
  {
		if ($this->result instanceof mysqli_stmt)
		{
			try {
				$result = $this->result->fetch();
			} catch (Exception $e) {
				$result = null;
			}
			if (is_null($result))
				return false; // No more rows
			else
			{
				// Contents of $this->row is copied over into new array to FORCE references to be UNSET
				$row = array();
				foreach($this->row as $key=>$value)
					$row[$key] = $value;
				return $row;
			}
		}
		else
			return $this->result->fetch_assoc();
  }
}
?>