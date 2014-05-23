<?php // coding: latin1
/**
 * Framework Classes
 *
 * Copyright 2008-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id: class.framework.php 4 2011-12-18 12:22:42Z ghdpro $
 * @package Visei_Framework
 */

class Template
{
	const FUNCTION_NESTING_LIMIT = 5;
	
	protected static $_cache = array();
	protected $_template = '';
	protected $_vars = array();
	
	/**
	 * Constructor. Accepts two optional arguments in random order:
	 * array   = initialize variables
	 * string  = template name
	 */
	public function __construct()
	{
		$args = func_get_args();
		foreach($args as $arg)
		{
			if (is_array($arg))
				foreach($arg as $key=>$value)
					$this->_vars[strtolower(trim($key))] = $value;
			elseif (is_string($arg))
				$this->_template = $arg;
		}
	}

	/**
	 * Magic method for getting template variables.
	 */ 
	public function __get($name)
	{
		$name = strtolower($name);
		if (isset($this->_vars[$name]))
			return $this->_vars[$name];
		throw new ErrorException('Undefined template variable <i>'.$name.'</i>');
	}

	/**
	 * Magic method for setting template variables.
   */ 
	public function __set($name,$value)
	{
		$this->_vars[strtolower($name)] = $value;
	}
	
	/**
	 * Magic method for getting status of template variables.
	 */ 
	public function __isset($name)
	{
		return isset($this->_vars[$name]);
	}
	
	/**
	 * Magic method for converting object to string.
	 */ 
	public function __toString()
	{
		return $this->Fetch();
	}
	
	/**
	 * Method for returning error messages inline (when in DEBUG mode).
	 */ 
	protected function Error($message)
	{
		if (defined('DEBUG'))
			return '['.SafeHTML($message).']';
		else
			return '';
	}
	
	/**
	 * preg_replace_callback function for variables: {$variable}
	 */
	protected function ReplaceVariable($matches)
	{
		// Get name & option (if defined)
		$name = ( isset($matches[1]) ? $matches[1] : '' );
		$option = null;
		if (($pos = strpos($name,'|')) !== false)
		{
			$option = strtolower(trim(substr($name,$pos+1)));
			$name = substr($name,0,$pos);
		}
		$name = strtolower(trim($name));
		// Get value
		$value = '';
		if ((strpos($name,'[') !== false) && preg_match('/^([a-zA-Z0-9-_\. ]+)\[([a-zA-Z0-9-_\. ]+)\]$/',$name,$matches))
		{
			// Variable is an array {$var[index]}
			$name = $matches[1];
			$index = $matches[2];
			if (isset($this->_vars[$name][$index]))
				$value = $this->_vars[$name][$index];
			elseif (is_null($option))
				return $this->Error('variable $'.$name.'['.$index.'] not defined');
		}
		elseif (isset($this->_vars[$name]))
			$value = $this->_vars[$name];
		elseif (is_null($option))
			return $this->Error('variable $'.$name.' not defined');
		// Return value
		switch ($option)
		{
			case 'escape': // {$var|escape}
				return SafeHTML($value);
				
			case 'url': // {$var|url}
				return SafeHTML(FixURL($value));
			
			default: // {$var} with either 1) no option 2) empty option 3) unrecongized option
				return (string)$value;
		}
	}
	
	/**
	 * Parser function #config - retrieve config variables
	 * Usage: {#config: node | node | node }
	 */
	protected function ParserFunction_Config($params)
	{
		foreach ($params as $key=>$value)
			$params[$key] = strtolower(trim($value));
		if (isset($params[1]) && isset($params[2]) && isset($params[3]) && isset($GLOBALS['config'][$params[0]][$params[1]][$params[2]][$params[3]]))
			return $GLOBALS['config'][$params[0]][$params[1]][$params[2]][$params[3]];
		elseif (isset($params[1]) && isset($params[2]) && isset($GLOBALS['config'][$params[0]][$params[1]][$params[2]]))
			return $GLOBALS['config'][$params[0]][$params[1]][$params[2]];
		elseif (isset($params[1]) && isset($GLOBALS['config'][$params[0]][$params[1]]))
			return $GLOBALS['config'][$params[0]][$params[1]];
		// Default (none or to many nodes passed)
		if (isset($GLOBALS['config'][$params[0]]))
			return $GLOBALS['config'][$params[0]];
		else
			return $this->Error('variable $config['.$params[0].'] not defined');
	}

	/**
	 * Parser function #if - returns first value if variable value is not-empty, or second value if it is
	 * Usage: {#if: variable | true | false }
	 */ 
	protected function ParserFunction_If($params)
	{
		if (!isset($params[1]))
			return $this->Error('if: required argument missing');
		$name = strtolower(trim($params[0]));
		if (isset($this->_vars[$name]) && !empty($this->_vars[$name]))
			return $params[1]; // true
		else
			return ( isset($params[2]) ? $params[2] : '' ); // false
	}
	
	/**
	 * Parser function #ifexpr - returns first value if expression value is true, or second value if it is false
	 * Usage: {#ifexpr: expression | true | false }
	 */ 
	protected function ParserFunction_Ifexpr($params)
	{
		$expression = strtolower(trim($params[0]));
		$result = preg_match('/^([a-z0-9_\- ]+)([%\<\>!=&\|]{1,2})([a-z0-9_\- ]+)$/',$expression,$matches);
		if (!$result || !isset($matches[1]) || !isset($matches[2]) || !isset($matches[3]))
			return $this->Error('ifexpr: failed to parse expression "'.$expression.'"');
		// Get values
		$value1 = strtolower(trim($matches[1]));
		if (isset($this->_vars[$value1]))
			$value1 = $this->_vars[$value1];
		$value2 = strtolower(trim($matches[3]));
		if (isset($this->_vars[$value2]))
			$value2 = $this->_vars[$value2];
		$operator = strtolower(trim($matches[2]));
		// Get results
		$true = (isset($params[1]) ? $params[1] : '');
		$false = (isset($params[2]) ? $params[2] : '');
		// Evaluate
		switch ($operator)
		{
			case '%':
				if ($value2 == 0) // Division by zero = return false
					return $false;
				return ((int)$value1 % (int)$value2 ? $true : $false);
				
			case '=':
			case '==':
				return ($value1 == $value2 ? $true : $false);

			case '!=':
			case '<>':
				return ($value1 != $value2 ? $true : $false);
				
			case '>':
				return ($value1 > $value2 ? $true : $false);

			case '<':
				return ($value1 < $value2 ? $true : $false);

			case '>=':
				return ($value1 >= $value2 ? $true : $false);

			case '<=':
				return ($value1 <= $value2 ? $true : $false);
				
			case '&':
			case '&&':
				return ($value1 && $value2 ? $true : $false);
				
			case '|':
			case '||':
				return ($value1 || $value2 ? $true : $false);

			default:
				return $this->Error('ifexpr: failed to parse expression "'.$expression.'"');
		}
	}

	/**
	 * Parser function #ifconst - compare variable against constant
	 * Usage: {#ifconst: variable | constant | true | false}
	 */
	protected function ParserFunction_Ifconst($name,$params)
	{
		if (!isset($params[1]) || !isset($params[2]))
			return $this->Error('ifconst: required arguments missing');
		$constant = trim($params[1]);
		if (!defined($constant))
			return $this->Error('constant '.$constant.' not defined');
		elseif ($this->_vars[$name] == constant($constant))
			return $params[2]; // true
		else
			return ( isset($params[3]) ? $params[3] : '' ); // false
	}

	/**
	 * Parser function #size - formats file size
	 * Usage: {#size: variable | decimals }
	 */
	protected function ParserFunction_Size($name,$params)
	{
		// If decimals is "b", return file size as bytes with thousand separators
		if (isset($params[1]) && (strtolower(trim($params[1])) == 'b'))
			return number_format($this->_vars[$name],0,'.',',');
		// Otherwise return formatted file size
		$decimals = 1;
		if (isset($params[1]) && is_numeric($params[1]))
			$decimals = intval($params[1]);
		return FormatSize($this->_vars[$name],$decimals);
	}
	
	/**
	 * preg_replace_callback function for parser functions: {#function: etc }
	 */
	protected function ParserFunction($matches)
	{
		if (!isset($matches[1]) || empty($matches[1]))
			return $this->Error('parser function syntax error');
		$function = strtolower(trim($matches[1]));
		$params = array();
		if (isset($matches[2]))
			$params = explode('|',$matches[2]);
		// All functions have at least one parameter
		if (!isset($params[0]))
			return $this->Error($function.': required arguments missing');
		switch ($function)
		{
			case 'config':
			case 'if':
			case 'ifexpr':
				return $this->{'ParserFunction_'.ucfirst($function)}($params);

			case 'ifconst':
			case 'size':
				// Get variable name
				$name = strtolower(trim($params[0]));
				if (!isset($this->_vars[$name]))
					return $this->Error('variable $'.$name.' not defined');
				return $this->{'ParserFunction_'.ucfirst($function)}($name,$params);
			
			default:
				return $this->Error('parser function #'.$function.' not defined');
		}
	}
	
	/**
	 * Template parser. Config parser function is parsed ahead of other functions.
	 */
	protected function Parse()
	{
		$result = preg_replace_callback('/\{\$(.*)\}/U',array($this,'ReplaceVariable'),self::$_cache[$this->_template]);
		// Loop through nested functions until either no more replacements are being done or maximum level is reached (to avoid infinite loops)
		$i = 0;
		$count = 0;
		do {
			$result = preg_replace_callback('/\{\#([a-zA-Z0-9-_\. ]+)\:([^\{]*?)\}/',array($this,'ParserFunction'),$result,-1,$count);
			$i++;
		} while (($count != 0) && ($i < Template::FUNCTION_NESTING_LIMIT));
		return $result;
	}
	
	/**
	 * Retrieves template from disc (or cache) and returns output of Parse()
	 */
	public function Fetch($template = null)
	{
		if (!is_null($template))
			$this->_template = $template;
		if (!isset(self::$_cache[$this->_template]))
		{
			if (is_null($this->_template))
				throw new ErrorException('No template specified');
			if (strpos($this->_template,'/') !== false)
				throw new ErrorException('Template <i>'.SafeHTML($this->_template).'</i> is invalid');
			if (!isset($GLOBALS['config']['path']['template']))
				throw new ErrorException('Template path not specified');
			$file = $GLOBALS['config']['path']['template'].'/'.$this->_template.'.tpl';
			if (!file_exists($file))
				throw new ErrorException('Template <i>'.$file.'</i> not found');
			self::$_cache[$this->_template] = file_get_contents($file);
		}
		return $this->Parse();
	}
	
	/**
	 * Outputs template to browser, including various header fields. Intended for layout templates.
	 */ 
	public function Display($template = null,$type = 'text/html',$nocache = true)
	{
		if (!headers_sent())
		{
			header('Content-Type: '.$type.'; charset=utf-8');
			if ($nocache)
			{
				header('Cache-Control: private, no-store, no-cache, must-revalidate');
				header('Pragma: no-cache');
				header('Expires: 0');
			}
			// Output before starting GZIP handler will result in corrupt page
			if (ob_get_length())
			{
				if (defined('DEBUG'))
					throw new ErrorException('Output buffer not empty');
				else
					ob_clean();
			}
			// Start GZIP handler
			ob_start('ob_gzhandler');
		}
		echo $this->Fetch($template);
	}
}

abstract class Controller
{
	protected static $config = null;
  protected static $conn = null;
  protected static $cache = null;
  protected static $session = null;
	protected $urlself = '';
	protected $controller = '';
	protected $action = '';
	protected $command = '';
	protected $params = array();

	public function __construct()
	{
		if (is_null(static::$config) && isset($GLOBALS['config']))
			static::$config = $GLOBALS['config'];
    if (is_null(static::$conn) && isset($GLOBALS['conn']))
      static::$conn = $GLOBALS['conn'];
    if (is_null(static::$cache) && isset($GLOBALS['cache']))
      static::$cache = $GLOBALS['cache'];
    if (is_null(static::$session) && isset($GLOBALS['session']))
      static::$session = $GLOBALS['session'];
		// Options
		$this->urlself = $_SERVER['SCRIPT_NAME'];
		$request = (strpos($_SERVER['REQUEST_URI'],'?') !== false ? substr($_SERVER['REQUEST_URI'],0,strpos($_SERVER['REQUEST_URI'],'?')) : $_SERVER['REQUEST_URI']);
		$this->params = explode('/',$request);
		foreach($this->params as $k=>$v)
			if (empty($v)) unset($this->params[$k]);
		$this->controller = array_shift($this->params);
    if (count($this->params) > 0)
    {
      $action = trim(reset($this->params));
      if (((string)(int)$action) !== ((string)$action))
        $this->action = trim(urldecode(array_shift($this->params)));
    }
    if (count($this->params) > 0)
    {
      $command = trim(reset($this->params));
      if (((string)(int)$command) !== ((string)$command))
        $this->command = trim(array_shift($this->params));
    }
	}

	public function Run()
	{
		if (method_exists($this,'Action'.ucfirst($this->action)))
      $this->{'Action'.ucfirst(strtolower($this->action))}();
		else
      $this->ActionDefault();
	}

	protected function ActionDefault()
	{
		throw new Exception('No default action defined');
	}
}

abstract class Render
{
  const ORDER_ASC     		= 'asc';
  const ORDER_DESC    		= 'desc';
	const LIMIT_PERPAGE 		= 50;
	const LIMIT_PERPAGE_MAX	= 1000;
	const LIMIT_SEARCH  		= 500;

  protected static $config = null;
  protected static $conn = null;
	protected static $cache;
  protected static $session = null;
	protected $values = array();
	protected $url = null;
	protected $colcount = 0;
  protected $rowcount = 0;
	protected $sortfields = array();
	protected $link = array();
	protected $search = '';
	protected $search_range = null;
	protected $sort = null;
	protected $order = null;
	protected $page = 0;
	protected $perpage = self::LIMIT_PERPAGE;
	protected $is_member = false;
  protected $is_staff = false;

  public function __construct($url)
  {
    if (is_null(static::$config) && isset($GLOBALS['config']))
      static::$config = $GLOBALS['config'];
    if (is_null(static::$conn) && isset($GLOBALS['conn']))
      static::$conn = $GLOBALS['conn'];
    if (is_null(static::$cache) && isset($GLOBALS['cache']))
      static::$cache = $GLOBALS['cache'];
    if (is_null(static::$session) && isset($GLOBALS['session']))
      static::$session = $GLOBALS['session'];
    $this->url = $url;
		$this->Init();
	}

	abstract protected function DefaultSort();
	
	protected function Init()
	{
		/*
		 * Session flags
		 */
		try {
			if (!is_null(static::$session))
			{
				$this->is_member = static::$session->IsMember();
				$this->is_staff = static::$session->IsStaff();
			}
		} catch (Exception $e) {
			// Ignore errors in case session object is not defiend
		}
		/*
		 * Search
		 */
		$this->search = (isset($_REQUEST['q']) ? trim(urldecode($_REQUEST['q'])) : '');
		if (!empty($this->search))
			$this->link['q'] = $this->search;
		/*
		 * Sort
		 */
		$this->sort = (isset($_REQUEST['sort']) ? strtolower(trim($_REQUEST['sort'])) : '');
		$defaultsort = $this->DefaultSort();
		// Check if a valid sort field was passed
		if (isset($this->sortfields[$this->sort]))
		{
			// Only if sort field does not match default sort it is inclued in $link
			if ($this->sort != $defaultsort)
				$this->link['sort'] = $this->sort;
		}
		else
			$this->sort = $defaultsort;
		/*
		 * Order
		 */
		$this->order = (isset($_REQUEST['order']) ? strtolower(trim($_REQUEST['order'])) : '');
		if (!in_array($this->order,array(self::ORDER_ASC,self::ORDER_DESC)) && isset($this->sortfields[$this->sort]))
			$this->order = $this->sortfields[$this->sort];
		// If sort field is not default sort OR if order is not default, include order in $link
		if (($this->sort != $defaultsort) || (isset($this->sortfields[$this->sort]) && ($this->order != $this->sortfields[$this->sort])))
			$this->link['order'] = $this->order;
		/*
		 * Page
		 */
		$this->page = (isset($_REQUEST['p']) ? intval($_REQUEST['p']) : 1);
		if ($this->page < 1)
			$this->page = 1;
		if ($this->page > 1)
			$this->link['p'] = $this->page;
		/*
		 * Per Page / Max
		 */
		if (isset($_REQUEST['pp']) || isset($_REQUEST['max']))
		{
			if (isset($_REQUEST['pp']))
				$this->perpage = intval($_REQUEST['pp']);
			elseif (isset($_REQUEST['max']))
				$this->perpage = intval($_REQUEST['max']);
			if ($this->perpage > self::LIMIT_PERPAGE_MAX)
				$this->perpage = self::LIMIT_PERPAGE_MAX;
			if (isset($_REQUEST['pp']))
				$this->link['pp'] = $this->perpage;
			elseif (isset($_REQUEST['max']))
				$this->link['max'] = $this->perpage;
		}
	}
	
	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
			case 'rowcount':
			case 'values';
			case 'link':
				return $this->{$name};

			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}
	
	abstract protected function QueryFields();
	
	protected function QueryCount()
	{
		return '';
	}
	
	protected function FilterSearch($query)
	{
		if (function_exists('FilterSearch'))
			return FilterSearch($query);
		else
			return $query;
	}
	
	protected function QuerySearch($index,$sort,$order,$port = 9312,$host = 'localhost')
	{
		$sphinx = new SphinxClient();
		$sphinx->SetServer($host,$port);
		$sphinx->SetConnectTimeout(1);
		$sphinx->SetArrayResult(true);
		$sphinx->SetLimits(0,static::LIMIT_SEARCH);
		$sphinx->SetMatchMode(SPH_MATCH_EXTENDED2);
		$sphinx->SetSortMode($order,$sort);
		// Limit results to a certain period
		if (!is_null($this->search_range))
			$sphinx->SetFilterRange($sort,time()-$this->search_range,time());
		// Check for multi-query search
		if (is_array($this->search))
		{
			foreach ($this->search as $query)
				if (!empty($query))
					$sphinx->AddQuery($this->FilterSearch($query),$index);
			$result = $sphinx->RunQueries();
		}
		else
			$result = $sphinx->Query($this->FilterSearch($this->search),$index);
		if ($result === false)
			throw new ErrorException('Search failed: '.$sphinx->GetLastError());
		// Return result
		$ids = array();
		$ids[] = 0; // Make IN() valid even if Sphinx returned nothing
		if (is_array($this->search))
		{
			// Merge results from multi-query search
			foreach ($result as $r)
				if (isset($r['matches']))
				{
					foreach($r['matches'] as $match)
						$ids[] = $match['id'];				
				}
		}
		elseif (isset($result['matches']))
		{
			foreach($result['matches'] as $match)
				$ids[] = $match['id'];
		}
		return $ids;
	}
	
	protected function QueryWhere()
	{
		return '';
	}
	
	protected function QuerySort()
	{
		return '';
	}
	
	protected function QueryLimit()
	{
		// Pagination
		return static::$conn->Limit($this->perpage,($this->page-1) * $this->perpage);
	}
	
	protected function Query($count = false)
	{
		// Reset values
		$this->values = array();
		// Query
		if ($count)
			return $this->QueryCount().$this->QueryWhere();
		else
			return $this->QueryFields().$this->QueryWhere().$this->QuerySort().$this->QueryLimit();
	}
	
  protected function HeaderSort($title,$name,$order,$templatename = 'table_header')
  {
		$template = new Template($templatename);
		$template->title = $title;
		// Build URL
		$link = $this->link;
		$link['sort'] = $name;
		if (($name == $this->sort) && ($this->order == self::ORDER_ASC))
			$link['order'] = self::ORDER_DESC;
		elseif (($name == $this->sort) && ($this->order == self::ORDER_DESC))
			$link['order'] = self::ORDER_ASC;
		else
			$link['order'] = $order;
		$template->link = $this->url.'?'.http_build_query($link,'','&amp;');
		// Determine which arrow icons should be displayed
		if (($name == $this->sort) && ($this->order == self::ORDER_ASC))
			$template->up = true;
		elseif (($name == $this->sort) && ($this->order == self::ORDER_DESC))
			$template->down = true;
		return $template;
  }
	
	protected function TableStyle()
	{
		return '';
	}
	
	protected function TableHeader()
	{
		return '';
	}
	
	protected function Table()
	{
		$result = '<table'.$this->TableStyle().'>'."\n";
		$result .= $this->TableHeader();
		$rs = static::$conn->Execute($this->Query(),$this->values);
		while ($row = $rs->Fetch())
		{
			$result .= $this->Row($row);
			$this->rowcount++;
		}
    if ($this->rowcount == 0)
      $result .= $this->ZeroRows();
		$result .= '</table>'."\n";
    return $result;
	}
	
	abstract protected function Row($row);

	protected function ZeroRows()
	{
		return '';
	}
	
	protected function Paginator()
	{
		// Get maximum number of pages
		$row = static::$conn->CacheQuery($this->Query(true),$this->values);
		$max = 1;
		if (isset($row['total']) && is_numeric($row['total']) && ($row['total'] > 0))
			$max = ceil($row['total'] / static::LIMIT_PERPAGE);
		// Only return paginator if it makes sense (max number of pages > 1)
		if ($max > 1)
		{
			$url = $this->url.'?'.(count($this->link) > 0 ? http_build_query($this->link,'','&amp;').'&amp;' : '').'p=';
			return Paginator($url,$this->page,$max);
		}
		return '';
	}

	public function View()
	{
		return $this->Table().$this->Paginator();
	}
}
?>