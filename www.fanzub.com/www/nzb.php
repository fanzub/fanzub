<?php // coding: latin1
/**
 * Fanzub - NZB
 *
 * Copyright 2009 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');

class NZBController extends Controller
{
  const MEMORY_LIMIT = 134217728; // 128 MB
  
  public function ActionDefault()
  {
    // If a _lot_ of NZB files are requested together, we might run out of memory
    ini_set('memory_limit','1024M');
    $ids = array();
    // Get post
    if (isset($_POST['id']) && is_array($_POST['id']))
    {
      foreach($_POST['id'] as $id)
      {
        $id = intval($id);
        if ($id > 0)
          $ids[$id] = $id;
      }
    }
    elseif (isset($this->params[0]) && ((int)$this->params[0] > 0))
      $ids[(int)$this->params[0]] = (int)$this->params[0];
    else
      die('<b>Error</b>: invalid post specified.');
    $nzb = '';
    $template = new Template();
    $template->body = '';
    foreach ($ids as $id)
    {
      try {
        $post = Post::FindByID($id);
      } catch (ActiveRecord_NotFoundException $e) {
        die('<b>Error</b>: post '.SafeHTML($id).' does not exist.');
      }
      // Updated time must be at least a minute ago, otherwise NZB cache may not be updated
      if (file_exists(Post::NZBFile($post->id)) && ($post->updated < (time()-60))) 
        $template->body .= file_get_contents(Post::NZBFile($post->id));
      else
        $template->body .= Post::NZB($post->id);
      // NZB name is taken from first post in list
      if (empty($nzb))
        $nzb = Post::NZBName($post->subject);
      // Don't try to download all BDMV raws in one go please
      if (memory_get_peak_usage() > self::MEMORY_LIMIT)
        die('<b>Error:</b> out of memory. Please download fewer NZB files together.');
    }
    // If multiple posts in one NZB, remove numbers from NZB name
    if (count($ids) > 1)
    {
      $nzb = preg_replace('/\s+\d+\.nzb$/i','.nzb',$nzb);
      $nzb = preg_replace('/\s+\d+\s+/i',' ',$nzb);
      $nzb = preg_replace('/\s+/i',' ',$nzb);
    }
    header('Content-disposition: inline; filename="'.$nzb.'"');
    // Output NZB file
    $template->Display('layout_nzb','application/x-nzb',false);
    // Count download(s)
    if (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR']))
    {
      // We don't store actual IP, only MD5 of the IP (for privacy)
      $ip = md5(strtolower(trim($_SERVER['REMOTE_ADDR'])));
      foreach ($ids as $id)
      {
        try {
          $download = Download::Find(array('postid' => $id,'userip' => $ip));
          // Found = ignore (count downloads only once)
        } catch (ActiveRecord_NotFoundException $e) {
          // Not found = add
          $download = new Download();
          $download->postid = $id;
          $download->userip = $ip;
          $download->Save();
        }
      }
    }
  }
}

// Display
$controller = new NZBController();
echo $controller->Run();
?>