<?php // coding: latin1
/**
 * NNTP Class
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */

class NNTP
{
  const CONNECT_TIMEOUT = 15;
  const RESPONSE_LENGTH = 512;
  const DATA_LENGTH     = 4096;
  const USER_AGENT      = 'Fanzub-Post 0.1';
  const YENC_LINELENGTH = 128;
  const YENC_PARTLENGTH = 250000; // Optimized for 15,000,000 byte files (60 parts)
  
  protected $fp = null;
  protected $code = null;
  protected $response = null;
  protected $group = array();
  protected $headers = array();
  
	/**
	 * Magic method for getting properties.
	 */ 
	public function __get($name)
	{
		switch($name)
		{
      case 'code':
      case 'response':
      case 'headers':
        return $this->{$name};
      
      case 'group':
      case 'first':
      case 'last':
        return $this->group[$name];
        
			default:
				throw new ErrorException('Undefined property '.get_class($this).'::$'.$name,0,E_WARNING);
		}
	}

  public function Connect($server,$port = 119)
  {
    $errno = 0;
    $errstr = false;
    $this->fp = fsockopen($server,$port,$errno,$errstr,self::CONNECT_TIMEOUT);
    if (!is_resource($this->fp))
      throw new NNTP_ConnectException($errstr,$errno);
    stream_set_blocking($this->fp,true);
    $this->Response();
    if (!in_array($this->code,array(200,201)))
      throw new NNTP_ConnectException('Connection failed: '.$this->response,$this->code);
  }
  
  public function Authenticate($username,$password)
  {
    $this->Command('AUTHINFO USER '.$username);
    if ($this->code == 381)
      $this->Command('AUTHINFO PASS '.$password);
    if ($this->code != 281)
      throw new NNTP_AuthenticationException('Authentication rejected: '.$this->response);
  }
  
  public function ModeReader()
  {
    $this->Command('MODE READER');
    if ($this->code == 200)
      return true; // Posting allowed
    elseif ($this->code == 201)
      return false; // Posting not allowed
    else
      throw new NNTP_CommandFailedException('MODE READER command rejected: '.$this->response,$this->code);
  }
  
  public function Group($group)
  {
    $this->Command('GROUP '.$group);
    if ($this->code != 211)
      throw new NNTP_CommandFailedException('GROUP command rejected: '.$this->response,$this->code);
    $parts = explode(' ',$this->response);
    if (isset($parts[2]))
      $this->group['first'] = intval($parts[2]);
    if (isset($parts[3]))
      $this->group['last'] = intval($parts[3]);
    if (isset($parts[4]))
      $this->group['group'] = trim($parts[4]);
  }
  
  public function Headers($first,$last)
  {
		$this->headers = array();
    // Get format
    $format = array('number');
    $this->Command('LIST OVERVIEW.FMT');
    if ($this->code != 215)
      throw new NNTP_CommandFailedException('LIST OVERVIEW.FMT command rejected: '.$this->response,$this->code);
    foreach ($this->Data() as $line)
    {
      $parts = explode(':',$line);
      $format[] = strtolower($parts[0]);
    }
    // Get headers
    $this->Command('XOVER '.$first.'-'.$last);
    if ($this->code != 224)
      throw new NNTP_CommandFailedException('XOVER command rejected: '.$this->response,$this->code);
    foreach ($this->Data() as $line)
    {
      $i = 0;
      $header = array();
      foreach (explode("\t",$line) as $item)
        $header[$format[$i++]] = trim($item);
      $this->headers[$header['message-id']] = $header;
    }
  }
  
  public function Post($newsgroup,$subject,$article,$name,$email = 'nospam@example.org')
  {
    $result = '';
    $this->Command('POST');
    if ($this->code != 340)
      throw new NNTP_CommandFailedException('POST command rejected: '.$this->response,$this->code);
    // Obtain message-id if returned by server
    if (preg_match('/(<.*>)/',$this->response,$matches))
      $result = $matches[1];
    // Headers
    fwrite($this->fp,'From: '.$email.' ('.$name.')'."\r\n");
    fwrite($this->fp,'Newsgroups: '.$newsgroup."\r\n");
    fwrite($this->fp,'Subject: '.$subject."\r\n");
    fwrite($this->fp,'User-Agent: '.self::USER_AGENT."\r\n");
    fwrite($this->fp,'Date: '.date('D, j M Y H:i:s').' UTC'."\r\n");
    fwrite($this->fp,"\r\n");
    // Body
    $lines = explode("\n",str_replace("\r",'',$article));
    foreach ($lines as $line)
    {
      // Lines starting with a dot should be escaped with an extra dot
      if (!empty($line) && ($line[0] == '.'))
        fwrite($this->fp,'.'.$line."\r\n");
      else
        fwrite($this->fp,$line."\r\n");
    }
    // End with a dot
    fwrite($this->fp,'.'."\r\n");
    $this->Response();
    if ($this->code != 240)
      throw new NNTP_CommandFailedException('Posting failed: '.$this->response,$this->code);
    return $result;
  }
  
  public function yEncode($string)
  {
    $result = '';
    $len = 0;
    for ($i = 0; $i < strlen($string); $i++)
    {
      $c = ord($string[$i]) + 42;
      switch ($c)
      {
        case 0:  // null
        case 9:  // tab
        case 10: // line feed
        case 13: // carriage return
        case 46: // .
        case 61: // =
          $result .= '=';
          $c += 64;
          $len++;
      }
      $result .= chr($c);
      $len++;
      if ($len > self::YENC_LINELENGTH)
      {
        $result .= "\r\n";
        $len = 0;
      }
    }
    if ($len > 0)
      $result .= "\r\n";
    return $result;
  }
  
  public function Quit()
  {
    $this->Command('QUIT');
    fclose($this->fp);
    $this->fp = null;
  }
  
  protected function Command($command)
  {
    if (!is_resource($this->fp))
      throw new NNTP_ConnectException('Not connected');
    if (strlen($command) > 510)
      throw new NNTP_CommandFailedException('Command exceeds allowed length');
    fwrite($this->fp,$command."\r\n");
    $this->Response();
  }
  
  protected function Response()
  {
    $this->response = fgets($this->fp,self::RESPONSE_LENGTH);
    $parts = explode(' ',$this->response);
    $this->code = intval($parts[0]);
  }
  
  protected function Data()
  {
    $result = array();
    while (!feof($this->fp))
    {
      $line = trim(fgets($this->fp,self::DATA_LENGTH));
      if ($line == '.')
        break;
      else
        $result[] = $line;
    }
    return $result;
  }
}

class NNTP_ConnectException extends CustomException {}
class NNTP_AuthenticationException extends CustomException {}
class NNTP_CommandFailedException extends CustomException {}
?>