<?php // coding: latin1
/**
 * PostgreSQL Class
 *
 * Copyright 2010-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id: class.postgresql.php 6 2012-01-19 11:48:51Z ghdpro $
 * @package Visei_Framework
 */

// Check for PostgreSQL extension
if (!extension_loaded('pgsql'))
	throw new ErrorException('PostgreSQL extension not found. Check PHP configuration.');
  
class PostgreSQL
{
	const CACHEQUERY_TIMEOUT = 900;
	const DEFAULT_PORT			 = 5432;
	
  protected $connect = array();
	protected $connection = null;
  protected $connected = false;
	protected $statements = array();
	protected $affected_rows = 0;
  protected static $stats = array();
  
  public function __construct($server = 'localhost',$database = '',$username = '',$password = '',$port = '')
  {
    $this->connect['server'] = $server;
    $this->connect['database'] = $database;
		$this->connect['schema'] = $database; // Assume schema name is identical to database name
    $this->connect['username'] = $username;
    $this->connect['password'] = $password;
		$this->connect['port'] = (empty($port) && !empty($server) ? self::DEFAULT_PORT : $port);
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

			case 'affected_rows':
      case 'connected':
        return $this->{$name};

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
				// Build params (omit hostname and password to connect using Unix sockets)
				$params = '';
				if (!empty($this->connect['server']) && ($this->connect['server'] != 'localhost'))
					$params = "host='".$this->connect['server']."' ";
				if (!empty($this->connect['port']) && ($this->connect['port'] != self::DEFAULT_PORT))
					$params = "port='".$this->connect['port']."' ";
				$params .= (!empty($this->connect['database']) ? "dbname='".$this->connect['database']."' " : '')
									.(!empty($this->connect['username']) ? "user='".$this->connect['username']."' " : '')
									.(!empty($this->connect['password']) ? "password='".$this->connect['password']."' " : '');
				// Connect
				if ($this->connect['persistent'])
					$this->connection = pg_pconnect($params);
				else
					$this->connection = pg_connect($params);
				// Make sure persistent connection is still alive
				if (!pg_ping($this->connection))
					throw new DatabaseException('Lost connection to database: unable to reconnect');
				// Enable Unicode
        pg_set_client_encoding($this->connection,'UNICODE');
				// Get prepared statements for this (persistent) connection
				$result = pg_query($this->connection,'SELECT * FROM "pg_prepared_statements"');
				if ($result === false)
					throw new SQLException(pg_last_error($this->connection),0,(string)$query);
				while ($row = pg_fetch_assoc($result))
					$this->statements[$row['name']] = $row;
        unset($this->connect['password']);
        $this->connected = true;
      } catch (Exception $e) {
  			throw new DatabaseException($e->getMessage());
      }
    }
  }
  
	public function Escape($value)
	{
    if (!$this->connected)
      $this->Open();
		return pg_escape_string($this->connection,$value);
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
		try {
			$start = GetMicroTime();
			// Passed existing statement as first argument
			if (is_object($query) && ($query instanceof PostgreSQLStatement))
			{
				$statement = $query;
				$query = $statement->query;
				$result = pg_execute($this->connection,$statement->name,$args);
			}
			// Prepared statement
			elseif ((strpos($query,'?') !== false) && (func_num_args() > 1) && (count($args) > 0))
			{
				$statement = new PostgreSQLStatement($query);
				// Prepare statement if it doesn't exist already
				if (!isset($this->statements[$statement->name]))
				{
					$result = pg_prepare($this->connection,$statement->name,$statement->query);
					if ($result !== false)
						$this->statements[$statement->name]['name'] = $statement->name;
				}
				// Execute statement if it exists (or was just prepared succesfully)
				if (isset($this->statements[$statement->name]))
					$result = pg_execute($this->connection,$statement->name,$args);
			}
			// Normal query
			else
			{
				$statement = null;
				$result = pg_query($query);
			}
			// Check for errors
			if ($result === false)
				throw new SQLException(pg_last_error($this->connection),0,(string)$query);
			$end = GetMicroTime();
			self::$stats['count']++;
			self::$stats['duration'] += ($end - $start);
			$this->affected_rows = pg_affected_rows($result);
			return new PostgreSQLRecordSet($result,$statement);
		} catch (Exception $e) {
			throw new SQLException(pg_last_error($this->connection),0,(string)$query);
		}
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
		if (!is_null($id))
			$query .= ' RETURNING "'.$id.'"';
		$rs = $this->Execute($query,$values);
		if (is_object($rs) && ($row = $rs->Fetch()))
			return $row[$id];
		else
			return $rs;
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
			$timestamp = $now;
		}
		return gmdate('Y-m-d H:i:s',$timestamp);
	}
	
	/**
	 * Takes time field from database and returns Unix timestamp.
	 */ 
	public function UnixTime($time)
	{
		if ((string)(int)$time == (string)trim($time))
			return intval($time);
		return strtotime($time);
	}

	/**
	 * Takes expression and returns boolean format suitable for database.
	 */
	public function Bool($expr)
	{
		// PostgreSQL prefers keywords TRUE/FALSE, but for ActiveRecord we return exactly what the database would return for a BOOL field
		return (boolval($expr) ? 't' : 'f');
	}

	/**
	 * Returns formatted field name for WHERE
	 */
	public function WhereField($name,$is_text = false)
	{
		if ($is_text)
			return 'lower("'.$name.'") = lower(?)'; // Look ups of text fields are presumed to be case-insenstive
		return '"'.$name.'" = ?';
	}
	
	/**
	 * Returns properly formatted LIMIT clause.
	 */
	public function Limit($limit,$offset = 0)
	{
		return ' LIMIT '.intval($limit).($offset > 0 ? ' OFFSET '.intval($offset) : '');
	}
}

class PostgreSQLStatement
{
	protected $query = null;
	protected $name = null;
	
	public function __construct($query)
	{
		$this->query = preg_replace_callback('/\?/i',
																				 function ($match)
																				 {
																					 static $counter = 0;
																					 $counter++;
																					 return '$'.$counter;
																				 },
																				 $query);
		$this->name = md5($query);
	}

	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
			case 'query':
			case 'name':
				return $this->{$name};

			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
}

class PostgreSQLRecordSet
{
	protected $result = null;
	protected $statement = null;
	protected $num_rows = 0;
	
  public function __construct($result,$statement = null)
  {
		$this->result = $result;
		$this->statement = $statement;
		$this->num_rows = pg_num_rows($result);
  }
  
  public function __destruct()
  {
		$this->Close();
  }

	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
			case 'statement':
			case 'num_rows':
				return $this->{$name};

			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
  
  public function Close()
  {
		try	{
			pg_free_result($this->result);
		} catch (Exception $e) {
		} 
  }
  
  public function Fetch()
  {
		return pg_fetch_assoc($this->result);
  }
}
?>