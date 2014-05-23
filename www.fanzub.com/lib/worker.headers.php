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
require_once('../lib/class.nntp.php');

class Headers extends Cron
{
  const LIMIT_HEADERS = 75000;
  
  protected $server = null;
  protected $nntp = null;
  protected $newsgroups = array();
  protected $headers = array();
  protected $authors = array();
  protected $first = 0;
  protected $last = 0;
  
  public function __construct()
  {
    parent::__construct();
    $this->Limits('1024M');
  }

  public function Run($id = null)
  {
    echo $this->Title('Headers');
    if (is_null($id) || ($id <= 0))
      throw new ErrorException('No server specified');
    $this->server = (int)$id;
    if (!isset(static::$config['servers']['host'][$this->server]))
      throw new ErrorException('Server not defined in configuration');
    // Cache groups
    $this->newsgroups = array();
    $groups = Newsgroup::FindAll();
    if (!is_array($groups))
      $groups = array($groups);
    foreach ($groups as $group)
      $this->newsgroups[$group->id] = $group->name;
    // Connect to server
    $this->Connect();
    // Get groups by server
    $servergroups = ServerGroup::FindByServerID($this->server);
    if (!is_array($servergroups))
      $servergroups = array($servergroups);
    // Reverse mapping
    $grouplookup = array_flip($this->newsgroups);
    // Loop through groups
    foreach ($servergroups as $servergroup)
    {
      // Get group
      echo '<p>Getting new headers for <b>'.$this->newsgroups[$servergroup->groupid].'</b>: ';
      $this->nntp->Group($this->newsgroups[$servergroup->groupid]);
      // Get range
      if (!$this->Range($servergroup->last))
      {
        echo 'no new headers</p>';
        continue;
      }
      echo $this->first.' - '.$this->last.' ('.($this->last-$this->first).')<br />';
      // Get & process headers
      $this->GetHeaders();
      // Save headers
      $found = 0;
      $new = 0;
      foreach ($this->headers as $subject=>$header)
      {
        echo SafeHTML($subject);
        // Match group names with group ids in database
        $groups = array();
        if (isset($header['groups']) && is_array($header['groups']))
          foreach ($header['groups'] as $group)
            if (isset($grouplookup[$group]))
              $groups[] = $grouplookup[$group];
        $result = Article::Update($header['author'],$subject,$header['date'],$header['total'],$groups,$header['parts']);
        if ($result)
        {
          echo ' - <i><b style="color:#000099">Found</b></i><br />'."\n";
          $found++;
        }
        else
        {
          echo ' - <i><b style="color:#009900">New</b></i><br />'."\n";
          $new++;
        }
      }
      echo '<b>'.$new.'</b> new articles, updated <b>'.$found.'</b> articles</p>';
      // Save server group
      if ($this->last <= $this->nntp->last)
        $servergroup->last = $this->last;
      else
        $servergroup->last = $this->nntp->last;
      $servergroup->Save();
    }
    // Update posts
    echo '<p>Updating posts: ';
    $posts = Post::Update();
    echo '<b>'.$posts.'</b></p>'; 
    $this->Quit();
  }
  
  protected function Connect()
  {
    echo '<p>Connecting to <b>'.static::$config['servers']['host'][$this->server].'</b></p>';
    $this->nntp = new NNTP();
    $this->nntp->Connect(static::$config['servers']['host'][$this->server]);
    $this->nntp->Authenticate(static::$config['servers']['user'][$this->server],static::$config['servers']['pass'][$this->server]);
    $this->nntp->ModeReader();
  }
  
  protected function Quit()
  {
    $this->nntp->Quit();
  }
  
  protected function Range($serverlast)
  {
    if ($serverlast == $this->nntp->last)
      return false;
    elseif (($serverlast < $this->nntp->first) || ($serverlast > $this->nntp->last))
    {
      // Out of range: reset
      $this->first = $this->nntp->first;
      $this->last = $this->nntp->first + self::LIMIT_HEADERS;
    }
    else
    {
      // In range
      $this->first = $serverlast;
      $this->last = $serverlast + self::LIMIT_HEADERS;
      if ($this->last > $this->nntp->last)
        $this->last = $this->nntp->last;
    }
    return true;
  }
  
  protected function GetHeaders()
  {
    $this->nntp->Headers($this->first,$this->last);
    $this->headers = array();
    foreach ($this->nntp->headers as $header)
    {
      // Find all xx/xx pairs (x=number)
      if (preg_match_all('/(\d+[\/-]\d+)/',$header['subject'],$matches))
      {
        // Match only last sequence
        $match = end($matches[1]);
        // Get current / total part count
        preg_match('/(\d+)([\/-])(\d+)/',$match,$matches);
        $current = intval($matches[1]);
        $total = intval($matches[3]);
        // Many posts may include a "0" text part, which we don't want
        if ($current > 0)
        {
          // Replace sequence
          $header['subject'] = FixUnicode($header['subject']);
          $subject = substr_replace($header['subject'],'1'.$matches[2].$matches[3],strrpos($header['subject'],$match),strlen($match));
          if (!isset($this->headers[$subject]))
          {
            // Initialize entry
            $this->headers[$subject]['author'] = FixUnicode(substr($header['from'],0,100));
            $this->headers[$subject]['date'] = strtotime($header['date']);
            $this->headers[$subject]['total'] = $total;
            $this->headers[$subject]['parts'] = array();
            if (isset($header['xref']))
            {
              $this->headers[$subject]['groups'] = array();
              $result = preg_match_all('/(alt.*)[\:]([0-9]+)/U',$header['xref'],$matches);
              if ($result && isset($matches[1]))
                $this->headers[$subject]['groups'] = $matches[1];
            }
          }
          else
          {
            // Get earliest date
            $date = strtotime($header['date']);
            if (($date > 0) && ($this->headers[$subject]['date'] > $date))
              $this->headers[$subject]['date'] = $date;
          }
          $this->headers[$subject]['parts']['size'][$current] = $header['bytes'];
          $this->headers[$subject]['parts']['id'][$current] = str_replace(array('<','>'),'',$header['message-id']);
        }
      }
    }
    // Filter non multi-part articles
    foreach ($this->headers as $subject=>$header)
    {
      if (count($header['parts']['id']) == 0)
        unset($headers[$subject]);
    }
  }
}
?>