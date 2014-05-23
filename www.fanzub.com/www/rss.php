<?php // coding: latin1
/**
 * Fanzub - RSS
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');
require_once('../lib/class.render.php');

class RSSController extends Controller
{
  public function ActionDefault()
  {
    $template = new Template();
    $render = new RSSRender(static::$config['url']['rss']);
    // Title
    if (isset($_REQUEST['q']) && !empty($_REQUEST['q']))
      $template->title = SafeHTML(trim($_REQUEST['q']));
    elseif (isset($_REQUEST['cat']) && !empty($_REQUEST['cat']))
    {
      $cat = trim($_REQUEST['cat']);
      $template->title = SafeHTML($cat != 'dvd' ? ucfirst($cat) : strtoupper($cat));
    }
    else
      $template->title = 'All';
    $template->title .= ' :: ';
    // RSS
    $template->rss = 'http://'.static::$config['url']['domain'].static::$config['url']['rss'];
    if (count($render->link) > 0)
      $template->rss .= '?'.http_build_query($render->link,'','&amp;');
    // Result
    $template->items = $render->View();
    $template->Display('layout_rss','text/xml');
  }
}

// Display
$controller = new RSSController();
echo $controller->Run();
?>