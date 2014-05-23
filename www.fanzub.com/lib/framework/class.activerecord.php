<?php // coding: latin1
/**
 * ActiveRecord Class
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id: class.activerecord.php 8 2012-01-19 12:19:40Z ghdpro $
 * @package Visei_Framework
 */

abstract class ActiveRecord
{
  const TYPE_TEXT    = 1;
  const TYPE_INTEGER = 2;
  const TYPE_FLOAT   = 3;
	const TYPE_BLOB    = 4;
  
  const COLUMN_CACHE_EXPIRE = 900;

  protected static $conn = null;
  protected static $cache = null;
  protected static $table = null;
  protected static $columns = null;
  protected $_key = null;
  protected $fields = array();
  protected $changes = array();
  
  public function __construct()
  {
    static::Init();
  }
  
	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
      case 'fields':
				return array_merge($this->fields,$this->changes);

			default:
				if (isset($this->changes[$name]))
					return $this->changes[$name];
        elseif (isset($this->fields[$name]))
          return $this->fields[$name];
        elseif (isset(static::$columns[$name]))
          return null;
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
        if (isset(static::$columns[$name]))
					$this->changes[$name] = $value;
        else
          throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
	
	/**
	 * Magic method for getting status of properties.
	 */ 
	public function __isset($name)
	{
		return (isset($this->fields[$name]) || isset($this->changes[$name]));
	}

  /**
   * Magic method for dynamically handling calls to the following static functions:
   * - FindAll()
   * - FindByField()
   * - FindOrCreate()
   * - FindOrCreateByField()
   */ 
  public static function __callStatic($name,$arguments)
  {
    static::Init();
    if (preg_match('/^(findall|findorcreate|find)(by)?(.*)/',strtolower($name),$matches))
    {
      // Where - Either By{Field} or by primary key. If missing assume FindAll()
      $where = null;
      if (isset($matches[2]) && ($matches[2] == 'by') && isset($matches[3]) && isset(static::$columns[$matches[3]]) && isset($arguments[0]))
        $where = array($matches[3] => $arguments[0]);
      elseif (isset($arguments[0]))
        $where = $arguments[0];
      // Find
      if ($matches[1] == 'find')
        return static::Find($where);
      if ($matches[1] == 'findall')
        return static::Find();
      // FindOrCreate
      try {
        return static::Find($where);
      } catch (ActiveRecord_NotFoundException $e) {
        return new static();
      }
    }
    else
      throw new ErrorException('Call to undefined method '.SafeHTML(get_called_class()).'::'.SafeHTML($name).'()');
  }
  
  /**
   * Initializes static fields, including a call to Columns() to get field types.
   */ 
  protected static function Init()
  {
    if (is_null(static::$table))
      static::$table = strtolower(get_called_class());
    if (is_null(static::$conn) && isset($GLOBALS['conn']))
      static::$conn = $GLOBALS['conn'];
    if (is_null(static::$cache) && isset($GLOBALS['cache']))
      static::$cache = $GLOBALS['cache'];
    if (is_null(static::$columns))
      static::$columns = static::Columns();
  }
  
  /**
   * Retrieves field types from database. Result is cached in order to prevent having to do this with each page request.
   */ 
  protected static function Columns()
  {
    if (is_null(static::$cache) || (($data = static::$cache->Get('activerecord',static::$table.'::columns')) == false))
    {
      $data = array();
			// Find columns (and types)
			$rs = static::$conn->Execute('SELECT * FROM "information_schema"."columns" WHERE "table_schema" = ? AND "table_name" = ? ORDER BY "ordinal_position" ASC',static::$conn->schema,static::$table);
      while ($row = $rs->Fetch())
      {
				$row = array_change_key_case($row,CASE_LOWER); // Caveat: field name case is not standardized
        if (stripos($row['data_type'],'float') !== false)
          $data[$row['column_name']]['type'] = static::TYPE_FLOAT;
        elseif (stripos($row['data_type'],'double') !== false)
          $data[$row['column_name']]['type'] = static::TYPE_FLOAT;
        elseif (stripos($row['data_type'],'bigint') !== false)
          $data[$row['column_name']]['type'] = static::TYPE_FLOAT; // Treat BIGINT as FLOAT, as PHP has no "long" type
        elseif (stripos($row['data_type'],'int') !== false)
          $data[$row['column_name']]['type'] = static::TYPE_INTEGER;
        elseif (stripos($row['data_type'],'blob') !== false)
          $data[$row['column_name']]['type'] = static::TYPE_BLOB;
        else // Treat any other fields as text
          $data[$row['column_name']]['type'] = static::TYPE_TEXT;
				$data[$row['column_name']]['primarykey'] = false;
      }
			// Find primary key(s)
			$rs = static::$conn->Execute('SELECT * FROM "information_schema"."key_column_usage" WHERE "table_schema" = ? AND "table_name" = ? ORDER BY "ordinal_position" ASC',static::$conn->schema,static::$table);
      while ($row = $rs->Fetch())
      {
				$row = array_change_key_case($row,CASE_LOWER);
				if (isset($data[$row['column_name']]))
					$data[$row['column_name']]['primarykey'] = true;
			}
      if (!is_null(static::$cache))
        static::$cache->Set('activerecord',static::$table.'::columns',$data,static::COLUMN_CACHE_EXPIRE);
    }
    return $data;
  }
  
	/**
	 * Returns fields of priamry keys for use in prepared statements.
	 */
	protected function KeyFields()
	{
		$result = '';
    if (!is_null($this->_key))
    {
			$fields = array();
      foreach ($this->_key as $key=>$value)
				$fields[] = '"'.$key.'" = ?';
			$result = ' ('.implode(') AND (',$fields).') ';
		}
		return $result;
	}

	/**
	 * Returns fields of priamry keys for use in prepared statements (static version, uses $columns instead of $_key).
	 */
	protected static function KeyStatic()
	{
		// static::Init() is presumed to be called by calling function
		$fields = array();
    foreach (static::$columns as $name=>$value)
			if ($value['primarykey'])
				$fields[] = '"'.$name.'" = ?';
		return ' ('.implode(') AND (',$fields).') ';
	}
	
	/**
	 * Returns values of primary keys for use in prepared statements.
	 */
	protected function KeyValues()
	{
    if (!is_null($this->_key))
			return array_values($this->_key);
		else
			return array();
	}
  
  /**
   * Loads values from $row into object, including setting the primary key. Primarily to be used by Find() function.
   */ 
  public function Load(array $row)
  {
    foreach (static::$columns as $name=>$value)
    {
      if (isset($row[$name]))
        $this->fields[$name] = $row[$name];
      if ($value['primarykey'] && isset($row[$name]))
      {
        if (is_null($this->_key))
          $this->_key = array();
        $this->_key[$name] = $row[$name];
      }
    }
  }
	
	/**
	 * Reloads record from database (in case any fields were changed by functional default values)
	 */
	public function Reload()
	{
		if (!is_null($this->_key))
		{
			$rs = static::$conn->Execute('SELECT * FROM "'.static::$table.'" WHERE '.$this->KeyFields().static::$conn->Limit(1),$this->KeyValues());	
	    if ($rs->num_rows == 0)
	      throw new ActiveRecord_NotFoundException('Row not found in table <i>'.SafeHTML(static::$table).'</i>');
			$this->Load($rs->Fetch());
		}
	}

  /**
   * Saves values. If primary key is set performs UPDATE, otherwise INSERT
   */
  public function Save()
  {
    $result = false;
		// Make sure text values will be passed as strings to bind_param() in MySQL class
		foreach ($this->changes as $name=>$value)
			if ((static::$columns[$name]['type'] == self::TYPE_TEXT) && !is_null($value))
				$this->changes[$name] = (string)$value;
		if (!is_null($this->_key) && (count($this->Diff()) > 0)) // UPDATE
		{
			static::$conn->AutoUpdate(static::$table,$this->changes,$this->KeyFields(),$this->KeyValues());
			$result = true;
			// If primary key was changed during update, make sure the internal reference is up-to-date
			foreach ($this->changes as $name=>$value)
				if (static::$columns[$name]['primarykey'])
					$this->_key[$name] = $value;
		}
		elseif (is_null($this->_key)) // INSERT
		{
			// Find primary key field
			$pkey = null;
			foreach (static::$columns as $name=>$value)
				if ($value['primarykey'])
				{
					$pkey = $name;
					break;
				}
			$id = static::$conn->AutoInsert(static::$table,$this->changes,$pkey);
			$result = true;
			foreach (static::$columns as $name=>$value)
			{
				// Primary key was passed as a value before saving
				if ($value['primarykey'] && isset($this->changes[$name]))
					$this->_key[$name] = $this->changes[$name];
				elseif ($value['primarykey'] && !empty($id))
				{
					// Primary key is an auto increment value
					if (is_null($this->_key))
						$this->_key = array();
					$this->_key[$name] = $id;
					$this->fields[$name] = $id;
					break;
				}
			}
		}
		// Merge changes into fields
		$this->fields = array_merge($this->fields,$this->changes);
		// Reset changes
		$this->changes = array();
    return $result;
  }
	
	/**
	 * Returns an array of items that has changed since the last save.
   */ 
	public function Diff()
	{
		// New record = everything is new
		if (is_null($this->_key))
			return $this->changes;
		// No changes = no difference
		if (count($this->changes) == 0)
			return $this->changes;
		// Create list of:
		// * All valid items that exist in $changes but not in $fields (unless value is NULL)
		// * All valid items where $fields value doesn't match $changes value
		$diff = array();
		foreach (static::$columns as $name=>$value)
			if (!array_key_exists($name,$this->fields) && array_key_exists($name,$this->changes) && !is_null($this->changes[$name]))
				$diff[$name] = $this->changes[$name];
			elseif (array_key_exists($name,$this->fields) && array_key_exists($name,$this->changes) && ($this->fields[$name] != $this->changes[$name]))
				$diff[$name] = $this->changes[$name];
		return $diff;
	}
  
  /**
   * Deletes object from database. Accepts both instance or regular type (int, string etc) as parameter.
   */ 
  public static function Delete($param)
  {
    static::Init();
    if (is_object($param) && is_subclass_of($param,'ActiveRecord') && !is_null($param->_key))
    {
      $query = 'DELETE FROM "'.static::$table.'" WHERE '.$param->KeyFields();
			$args = $param->KeyValues();
    }
    elseif (is_array($param) && (count($param) > 0))
    {
			$fields = array();
			foreach ($param as $key=>$value)
				$fields[] = static::$conn->WhereField($key,(static::$columns[$key]['type'] == static::TYPE_TEXT));
			$args = array_values($param);
      $query = 'DELETE FROM "'.static::$table.'" WHERE ('.implode(') AND (',$fields).')';
    }
    elseif (is_int($param) ||  is_float($param) || is_string($param))
    {
			$query = 'DELETE FROM "'.static::$table.'" WHERE '.static::KeyStatic();
			$args = $param;
    }
    // Execute query
    if (isset($query) && !empty($query) && isset($args))
      static::$conn->Execute($query,$args);
  }
  
  /**
   * Finds object in database and returns (an array of) objects with values.
   */ 
  public static function Find($where = null,$query = null)
  {
    static::Init();
		// Build query if no custom query specified
		if (is_null($query))
		{
			$query = 'SELECT * FROM "'.static::$table.'"';
			// $where is an array with key/value pairs
			if (is_array($where) && (count($where) > 0))
			{
				$fields = array();
				foreach ($where as $key=>$value)
					$fields[] = static::$conn->WhereField($key,(static::$columns[$key]['type'] == static::TYPE_TEXT));
				$query .= ' WHERE ('.implode(') AND (',$fields).') ';
				$args = array_values($where);
			}
			// $where is a single value meant to match primary key
			elseif (!is_null($where))
			{
				$query .= ' WHERE '.static::KeyStatic();
				$args = $where;
			}
		}
		else
		// Custom query specified
		{
			// Get arguments (for prepared statements)
			if (func_num_args() > 2)
			{
				$args = func_get_args();
				if (isset($args[2]) && is_array($args[2]))
					$args = array_values($args[2]); // Third argument is array with values
				else
					$args = array_slice($args,2); // Use all arguments as values (except for where and query of course)
			}
		}
		if (isset($args))
			$rs = static::$conn->Execute($query,$args);
		else
			$rs = static::$conn->Execute($query);
    // No rows = raise error
    if ($rs->num_rows == 0)
      throw new ActiveRecord_NotFoundException('Row not found in table <i>'.SafeHTML(static::$table).'</i>');
    // One row = return single instance
    if (!is_null($where) && ($rs->num_rows == 1))
    {
      $row = $rs->Fetch();
      $instance = new static();
      $instance->Load($row);
      return $instance;
    }
    // Else: return array of instances
    $result = array();
    while ($row = $rs->Fetch())
    {
      $instance = new static();
      $instance->Load($row);
      $result[] = $instance;
    }
		// Do not return array for single result
		if (is_array($result) && (count($result) == 1))
			$result = reset($result);
    return $result;
  }
}

class ActiveRecord_NotFoundException extends CustomException {}
?>