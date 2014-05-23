<?php // coding: latin1
/**
 * Fanzub - Render
 *
 * Copyright 2009-2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */

class PostRender extends Render
{
	const LIMIT_PERPAGE   = 200;
	const LIMIT_SEARCH		= 1000;
	
	protected $category = null;
	protected $author = null;
	protected $hidden = true;
	
	protected function DefaultSort()
	{
		return null;
	}

	protected function Init()
	{
		/*
		 * Category
		 */
		if (isset($_REQUEST['cat']))
			$this->category = strtolower(trim($_REQUEST['cat']));
		if (!is_null($this->category))
		{
			$namecat = array_flip($GLOBALS['catname']);
			if (in_array($this->category,$GLOBALS['catname']) && isset($GLOBALS['catgroup'][$namecat[$this->category]]))
				$this->link['cat'] = $this->category;
		}
		/*
		 * Hidden
		 */
		if (isset($_REQUEST['hidden']))
		{
			$this->hidden = false;
			$this->link['hidden'] = '';
		}
		/*
		 * Call parent function *last*
	   */ 
		parent::Init();
		if (!empty($this->search))
			$this->link['q'] = $this->search;
	}

	protected function QueryFields()
	{
		return 'SELECT "posts".*,"postcat"."catid" FROM "posts" '
		      .'INNER JOIN "postcat" ON ("postcat"."postid" = "posts"."id") ';
	}
	
	protected function QueryCount()
	{
		return 'SELECT COUNT(DISTINCT "posts"."id") AS "total" FROM "posts" '
					.'INNER JOIN "postcat" ON ("postcat"."postid" = "posts"."id") ';
	}
	
	protected function QueryWhere()
	{
		$result = '';
		$where = array();
		// Search
		if (!empty($this->search))
		{
			try {
				$where[] = ' ("posts"."id" IN ('.implode(',',$this->QuerySearch('fanzub_main, fanzub_delta','post_date',SPH_SORT_ATTR_DESC)).')) ';
			} catch (Exception $e) {
				$where[] = ' ("posts"."id" = 0) '; // Return nothing
			}
		}
		// Category
		$namecat = array_flip($GLOBALS['catname']);
		if (in_array($this->category,$GLOBALS['catname']))
			$where[] = ' ("postcat"."catid" = '.$namecat[$this->category].') ';
		else
			$where[] = ' ("postcat"."primarycat" = 1) '; // Missing/invalid category = show all posts (with primary category)
		if (count($where) > 0)
			$result = 'WHERE '.implode(' AND ',$where).' ';
		return $result;
	}
	
	protected function QuerySort()
	{
		return 'ORDER BY "postcat"."post_date" DESC ';
	}

	protected function TableStyle()
	{
		return ' class="fanzub"';
	}
	
	protected function Row($row)
	{
		$result = '';
		// Class
		$class = '';
		if ($this->rowcount % 2)
			$class = 'shade';
		$result .= '<tr class="top'.(!empty($class) ? ' '.$class : '').'" title="'.SafeHTML($row['subject']).'">'."\n";
		// Category
		$catname = 'other';
		if (isset($GLOBALS['catname'][$row['catid']]))
			$catname = strtolower($GLOBALS['catname'][$row['catid']]);
		$result .= '<td rowspan="2"><input type="checkbox" name="id['.$row['id'].']" value="'.$row['id'].'" />'
		          .'<a href="'.static::$config['url']['base'].'?'.(!empty($search) ? 'q='.urlencode($search).'&amp;' : '').'cat='.urlencode($catname).'">'
							.'<img src="'.static::$config['url']['base'].'images/cat/'.$catname.'.png" alt="'.($catname != 'dvd' ? ucfirst($catname) : strtoupper($catname)).'" />'
							.'</a></td>'."\n";
		// File
		$result .= '<td class="file"><a type="application/x-nzb" href="'.static::$config['url']['nzb'].'/'.$row['id'].'">'.SafeHTML(Post::FilenameFilter($row['subject'])).'</a></td>'."\n";
		// Age
		$result .= '<td rowspan="2" class="age">'.Post::Age($row['post_date']).'d</td>'."\n";
		// Details
		$result .= '<td rowspan="2"><a href="javascript:Details('.$row['id'].');">Details</a></td>'."\n";
		$result .= '</tr>'."\n";
		$result .= '<tr class="bottom'.(!empty($class) ? ' '.$class : '').'" title="'.SafeHTML($row['subject']).'">'."\n";
		$stats = array();
		// Size & Date
		$stats[] = 'Size: '.FormatSize($row['size'],2).' | Date: '.gmdate('Y-m-d H:i',$row['post_date']).' UTC';
		// Parts
		if ($row['parts_found'] == $row['parts_total'])
			$stats[] = 'Parts: 100%';
		elseif ($row['parts_total'] > 0)
			$stats[] = 'Parts: <span class="warning">'.number_format(((int)$row['parts_found'] / (int)$row['parts_total']) * 100,2).'%</span>';
		else
			$stats[] = 'Parts: <span class="warning">0%</span>';
		// Files
		if (!empty($row['stats']))
		{
			parse_str($row['stats'],$files);
			if (count($files) > 0)
			{
				$list = array();
				foreach ($files as $type=>$count)
					$list[] = $count.' '.SafeHTML($type);
				$stats[] = 'Files: '.implode(', ',$list);
			}
		}
		$result .= '<td>'.implode(' | ',$stats).' <div id="post'.$row['id'].'" class="details"></div></td>'."\n";
		$result .= '</tr>'."\n";
		return $result;
	}

	protected function ZeroRows()
	{
		return '<tr class="nomatch"><td>Your search did not return any matches.</td></tr>'."\n";
	}
	
	protected function Paginator()
	{
		$template = new Template();
		$template->paginator = parent::Paginator();
		return $template->Fetch('nav');
	}
	
	public function View()
	{
		$result = $this->Table();
		if ($this->rowcount > 0)
			return '<form action="'.static::$config['url']['nzb'].'" method="post" id="nzb">'.$result.$this->Paginator().'</form>';
		else
			return $result;
	}
}

class RSSRender extends PostRender
{
	const LIMIT_PERPAGE   = 200;
	
	public function View()
	{
		$result = '';
		$rs = static::$conn->Execute($this->Query(),$this->values);
		while ($row = $rs->Fetch())
		{
			$template = new Template('rss_item');
			$template->title = Post::FilenameFilter($row['subject']);
			$template->link = 'http://'.static::$config['url']['domain'].static::$config['url']['nzb'].'/'.$row['id'];
			$template->vanity = '/'.rawurlencode(Post::NZBName($row['subject']));
			$template->desc = '<i>Age</i>: '.floor((time()-$row['post_date']+1) / 86400).' days<br />';
			$template->desc .= '<i>Size</i>: '.FormatSize($row['size'],2).'<br />';
			if ($row['parts_found'] == $row['parts_total'])
				$template->desc .= '<i>Parts</i>: 100%<br />';
			elseif ($row['parts_total'] > 0)
				$template->desc .= '<i>Parts</i>: '.number_format(((int)$row['parts_found'] / (int)$row['parts_total']) * 100,2).'%<br />';
			else
				$template->desc .= '<i>Parts</i>: 0%<br />';
			if (!empty($row['stats']))
			{
				parse_str($row['stats'],$files);
				if (count($files) > 0)
				{
					$list = array();
					foreach ($files as $type=>$count)
						$list[] = $count.' '.SafeHTML($type);
					$template->desc .= '<i>Files</i>: '.implode(', ',$list).'<br />';
				}
			}
			$template->desc .= '<i>Subject</i>: '.SafeHTML($row['subject']);
			$template->cat = 'other';
			if (isset($GLOBALS['catname'][$row['catid']]))
				$template->cat = strtolower($GLOBALS['catname'][$row['catid']]);
			$template->cat = ($template->cat != 'dvd' ? ucfirst($template->cat) : strtoupper($template->cat));
			$template->size = SafeHTML($row['size']);
			$template->date = gmdate('r',$row['post_date']);
			$result .= $template."\n";
		}
		return $result;
	}
}

class ExportRender extends PostRender
{
	const LIMIT_PERPAGE   = 200;
	
	const FORMAT_SERIAL		= 1;
	const FORMAT_JSON			= 2;
	
	protected $filter = true;
	protected $format = self::FORMAT_JSON;
	
	protected function Init()
	{
		/*
		 * Filter
		 */
		if (isset($_REQUEST['filter']))
			$this->filter = boolval($_REQUEST['filter']);
		/*
		 * Format
		 */
		if (isset($_REQUEST['format']))
		{
			if (trim(strtolower($_REQUEST['format'])) == 'serial')
				$this->format = self::FORMAT_SERIAL;
			else
				$this->format = self::FORMAT_JSON;
		}
		/*
		 * Call parent function *last*
	   */ 
		parent::Init();
	}

	protected function QueryFields()
	{
		if ($this->filter)
			return 'SELECT "posts".*,"postcat"."catid","authors"."name" AS "poster" FROM "posts" '
			      .'INNER JOIN "postcat" ON ("postcat"."postid" = "posts"."id") '
						.'INNER JOIN "authors" ON ("authors"."id" = "posts"."authorid") ';
		else
			return 'SELECT "posts".*,"postcat"."catid","authors"."name" AS "poster" FROM "posts" '
		        .'LEFT JOIN "postcat" ON ("postcat"."postid"  = "posts"."id" AND "postcat"."primarycat" = 1) '
						.'INNER JOIN "authors" ON ("authors"."id" = "posts"."authorid") ';
	}
	
	protected function QueryCount()
	{
		if ($this->filter)
			return 'SELECT COUNT(DISTINCT "posts"."id") AS "total" FROM "posts" '
						.'INNER JOIN "postcat" ON ("postcat"."postid" = "posts"."id") ';
		else
			return 'SELECT COUNT(DISTINCT "id") AS "total" FROM "posts" ';
	}
	
	protected function QueryWhere()
	{
		$result = '';
		$where = array();
		// Search
		if (!empty($this->search))
		{
			try {
				$where[] = ' ("posts"."id" IN ('.implode(',',$this->QuerySearch('fanzub_main, fanzub_delta','post_date',SPH_SORT_ATTR_DESC)).')) ';
			} catch (Exception $e) {
				$where[] = ' ("posts"."id" = 0) '; // Return nothing
			}
		}
		// Author (hidden search option)
		if (!is_null($this->author))
			$where[] = ' ("posts"."authorid" = '.$this->author.') ';
		// Category
		if ($this->filter)
		{
			$namecat = array_flip($GLOBALS['catname']);
			if (in_array($this->category,$GLOBALS['catname']))
				$where[] = ' ("postcat"."catid" = '.$namecat[$this->category].') ';
			else
				$where[] = ' ("postcat"."primarycat" = 1) '; // Missing/invalid category = show all posts (with primary category)
		}
		if (count($where) > 0)
			$result = 'WHERE '.implode(' AND ',$where).' ';
		return $result;
	}
	
	protected function QuerySort()
	{
		if ($this->filter)
			return 'ORDER BY "postcat"."post_date" DESC ';
		else
			return 'ORDER BY "posts"."post_date" DESC ';
	}

	public function View()
	{
		$result = array();
		$rs = static::$conn->Execute($this->Query(),$this->values);
		while ($row = $rs->Fetch())
		{
			unset($row['authorid']);
			$row['subject_filtered'] = Post::FilenameFilter($row['subject']);
			if (isset($GLOBALS['catname'][$row['catid']]))
				$row['category'] = $GLOBALS['catname'][$row['catid']];
			else
				$row['category'] = null;
			unset($row['catid']);
			if (!empty($row['stats']))
				parse_str($row['stats'],$row['stats']);
			else
				$row['stats'] = array();
			$row['hidden'] = boolval($row['hidden']);
			$result[] = $row;
		}
		if ($this->format == self::FORMAT_SERIAL)
			return serialize($result);
		else
			return json_encode($result);
	}
}
?>