<?php // coding: latin1
/**
 * Fanzub - Index
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');
require_once('../lib/class.render.php');

class IndexController extends Controller
{
  public function ActionDefault()
  {
    if (isset($_REQUEST['details']))
      return $this->Details(intval($_REQUEST['details']));
    $template = new Template();
    $template->rss = static::$config['url']['rss'];
    $template->searchbox = new Template('searchbox');
    $template->menu = new Template('menu');
    if (isset($_REQUEST['q']) || isset($_REQUEST['cat']) || isset($_REQUEST['p']))
    {
      $render = new PostRender(static::$config['url']['base']);
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
      // Search box variables
      $template->searchbox->query = (isset($_REQUEST['q']) ? trim($_REQUEST['q']) : '');
      if (isset($_REQUEST['cat']) && !empty($_REQUEST['cat']))
        $template->searchbox->cat = array(strtolower(trim($_REQUEST['cat'])) => ' selected="selected"');
      else
        $template->searchbox->cat = array('all' => ' selected="selected"');
      // RSS
      if (count($render->link) > 0)
        $template->rss .= '?'.http_build_query($render->link,'','&amp;');
      $template->searchbox->rss = '<a href="'.$template->rss.'" title="RSS"><img src="'.static::$config['url']['base'].'images/rss.png" width="14" height="14" alt="RSS" /></a>';
      // Result
      $template->body = $render->View();
      // Stop search engines (Google) from complaining a page with no results should return 404
      if ($render->rowcount == 0)
        $template->meta = '<meta name="robots" content="noindex,nofollow">';
      $template->footer = new Template('footer');
      $template->Display('layout');
    }
    else
      $template->Display('layout_splash');
  }
  
  protected function Details($id)
  {
    // Details screen may use a lot of memory if there are a lot of files in a post
    ini_set('memory_limit','512M');
    $template = new Template();
    $result = '';
    // Get post
    try {
      $post = Post::FindByID($id);
      $author = Author::FindByID($post->authorid);
    } catch (ActiveRecord_NotFoundException $e) {
      die('<b>Error</b>: post '.SafeHTML($this->params[0]).' does not exist.');
    }
    // Cache newsgroups
    try {
      $newsgroups = array();
      $groups = Newsgroup::FindAll();
      if (!is_array($groups))
        $groups = array($groups);
      foreach ($groups as $group)
        $newsgroups[$group->id] = $group->name;
    } catch (ActiveRecord_NotFoundException $e) {
      die('<b>Error</b>: database problem - no newsgroups defined.');
    }
    $result .= '<table cellspacing="0"><tr><th>Poster</th><th>Newsgroups</th></tr>'."\n";
    $poster = trim(preg_replace('/\s+/',' ',str_replace(array('@','(',')','[',']',',','.','!','-','#','^','$','+','/','\\'),' ',$author->name)));
    $result .= '<tr><td class="split"><a href="'.static::$config['url']['base'].'?'.http_build_query(array('q'=>'@poster '.$poster),'','&amp;').'">'.SafeHTML($author->name).'</a></td><td class="split">';
    try {
      $list = array();
      $groups = PostGroup::FindByPostID($post->id);
      if (!is_array($groups))
        $list[] = $newsgroups[$groups->groupid];
      else
        foreach ($groups as $group)
          $list[] = $newsgroups[$group->groupid];
      $result .= implode('<br />',$list);
    } catch (ActiveRecord_NotFoundException $e) {
      die('<b>Error</b>: database problem - post is not associated with any newsgroup.');
    }
    $result .= '</tr>'."\n".'</table><br />'."\n";
    // Get files
    try {
      $result .= '<table cellspacing="0">'."\n";
      $result .= '<tr><th>Date</th><th>Subject</th><th>Parts</th><th>Size</th></tr>'."\n";
      $articles = Article::Find(null,'SELECT * FROM `articles` WHERE `postid` = '.$post->id.' ORDER BY `subject` ASC');
      if (!is_array($articles))
        $articles = array($articles);
      foreach ($articles as $article)
      {
        $result .= '<tr>'."\n";
        $result .= '<td class="date">'.gmdate('Y-m-d H:i',$article->post_date).'</td>'."\n";
        $result .= '<td class="subject">'.SafeHTML($article->subject).'</td>';
        if ($article->parts_found != $article->parts_total)
          $result .= '<td class="parts"><span class="warning">'.SafeHTML($article->parts_found).' / '.SafeHTML($article->parts_total).'</span></td>';
        else
          $result .= '<td class="parts">'.SafeHTML($article->parts_found).' / '.SafeHTML($article->parts_total).'</span></td>';
        $result .= '<td class="size">'.FormatSize($article->size,2).'</td>';
        $result .= '</tr>'."\n";
      }
      $result .= '</table>'."\n";
    } catch (ActiveRecord_NotFoundException $e) {
      die('<b>Error</b>: database problem - post has no associated articles.');
    }
    $template->body = $result;
    $template->Display('layout_ajax');
  }
}

// Display
$controller = new IndexController();
echo $controller->Run();
?>