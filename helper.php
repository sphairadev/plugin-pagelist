<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

class helper_plugin_pagelist extends DokuWiki_Plugin {

  /* public */

  var $page       = NULL;    // associative array for page to list
                             // must contain a value to key 'id'
                             // can contain: 'title', 'date', 'user', 'desc', 'comments',
                             // 'tags', 'status' and 'priority'
                             
  var $style      = '';      // table style: 'default', 'dokuwiki', 'blind'
  var $showheader = false;   // show a heading line
  var $column     = array(); // which columns to show
  var $header     = array(); // language strings for table headers
  
  var $plugins    = array(); // array of plugins to extend the pagelist
  var $discussion = NULL;    // discussion class object
  var $tag        = NULL;    // tag class object
  
  var $doc        = '';      // the final output XHTML string
  
  /* private */
  
  var $_meta      = NULL;    // metadata array for page
  
  /**
   * Constructor gets default preferences
   *
   * These can be overriden by plugins using this class
   */
  function helper_plugin_pagelist(){
    $this->style      = $this->getConf('style');
    $this->showheader = $this->getConf('showheader');
    
    $this->column = array(
      'page'     => true,
      'date'     => $this->getConf('showdate'),
      'user'     => $this->getConf('showuser'),
      'desc'     => $this->getConf('showdesc'),
      'comments' => $this->getConf('showcomments'),
      'tags'     => $this->getConf('showtags'),
    );
    
    $this->plugins = array(
      'discussion' => 'comments',
      'tag'        => 'tags',
    );
  }
  
  /**
   * Return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-10',
      'name'   => 'Pagelist Plugin (helper class)',
      'desc'   => 'Functions to list several pages in a nice looking table',
      'url'    => 'http://www.wikidesign/en/plugin/pagelist/start',
    );
  }
  
  function getMethods(){
    $result = array();
    $result[] = array(
      'name'   => 'addColumn',
      'desc'   => 'adds an extra column for plugin data',
      'params' => array(
        'plugin name' => 'string',
        'column key' => 'string'),
    );
    $result[] = array(
      'name'   => 'startList',
      'desc'   => 'prepares the table header for the page list',
    );
    $result[] = array(
      'name'   => 'addPage',
      'desc'   => 'adds a page to the list',
      'params' => array("page attributes, 'id' required, others optional" => 'array'),
    );
    $result[] = array(
      'name'   => 'finishList',
      'desc'   => 'returns the XHTML output',
      'return' => array('xhtml' => 'string'),
    );
    return $result;
  }
  
  /**
   * Adds an extra column for plugins
   */
  function addColumn($plugin, $col){
    $this->plugins[$plugin] = $col;
    $this->column[$col] = true;
  }

  /**
   * Sets the list header
   */
  function startList(){
  
    // table style
    switch ($this->style){
    case 'dokuwiki':
      $class = 'inline';
      break;
    case 'blind':
      $class = 'blind';
      break;
    default:
      $class = 'pagelist';
    }
    $this->doc = '<table class="'.$class.'">'.DOKU_LF;
    $this->page = NULL;
    
    // check if some plugins are available - if yes, load them!
    foreach ($this->plugins as $plug => $col){
      if (!$this->column[$col]) continue;
      if (!$this->$plug = plugin_load('helper', $plug)) $this->column[$col] = false;
    }
        
    // header row
    if ($this->showheader){
      $this->doc .= DOKU_TAB.'<tr>'.DOKU_LF.DOKU_TAB.DOKU_TAB;
      $columns = array('page', 'date', 'user', 'desc');
      foreach ($columns as $col){
        if ($this->column[$col]){
          $this->doc .= '<th class="'.$col.'">'.hsc($this->getLang($col)).'</th>';
        }
      }
      foreach ($this->plugins as $plug => $col){
        if ($this->column[$col]){
          $this->doc .= '<th class="'.$col.'">'.hsc($this->$plug->th()).'</th>';
        }
      }
      $this->doc .= DOKU_LF.DOKU_TAB.'</tr>'.DOKU_LF;
    }
    return true;
  }
  
  /**
   * Sets a list row
   */
  function addPage($page){
    
    $id = $page['id'];
    if (!$id) return false;
    $this->page = $page;
    $this->_meta = NULL;
    
    // priority
    if (isset($this->page['priority']))
      $class = ' class="priority'.$this->page['priority'].'"';
    else
      $class = '';
    $this->doc .= DOKU_TAB.'<tr'.$class.'>'.DOKU_LF.DOKU_TAB.DOKU_TAB;
  
    $this->_pageCell($id);    
    if ($this->column['date']) $this->_dateCell($id);
    if ($this->column['user']) $this->_userCell($id);
    if ($this->column['desc']) $this->_descCell($id);
    foreach ($this->plugins as $plug => $col){
      if ($this->column[$col]) $this->_pluginCell($plug, $col, $id);
    }
    
    $this->doc .= DOKU_LF.DOKU_TAB.'</tr>'.DOKU_LF;
    return true;
  }
  
  /**
   * Sets the list footer
   */
  function finishList(){
    if (!isset($this->page)) $this->doc = '';
    else $this->doc .= '</table>'.DOKU_LF;
    return $this->doc;
  }

/* ---------- Private Methods ---------- */
  
  /**
   * Page title / link to page
   */
  function _pageCell($id){
    if ($this->page['image']){
      $title = '<img src="'.ml($this->page['image']).'" class="media"';
      if ($this->page['title']) $title .= ' title="'.hsc($this->page['title']).'"'.
        ' alt="'.hsc($this->page['title']).'"';
      $title .= ' />';
    } else {
      if (!$this->page['title']) $this->page['title'] = $this->_getMeta($id, 'title');
      if (!$this->page['title']) $this->page['title'] = str_replace('_', ' ', noNS($id));
      $title = hsc($this->page['title']);
    }
    $this->doc .= '<td class="page"><a href="'.wl($id).'" class="wikilink1"'.
      ' title="'.$id.'">'.$title.'</a></td>';
    return true;
  }
  
  /**
   * Date - creation or last modification date if not set otherwise
   */
  function _dateCell($id){    
    global $conf;
    if (!$this->page['date']){
      if ($this->column['date'] == 2)
        $this->page['date'] = $this->_getMeta($id, array('date', 'modified'));
      else
        $this->page['date'] = $this->_getMeta($id, array('date', 'created'));
    }
    $this->doc .= '<td class="date">'.date($conf['dformat'], $this->page['date']).'</td>';
    return true;
  }
  
  /**
   * User - page creator or contributors if not set otherwise
   */
  function _userCell($id){
    if (!$this->page['user']){
      if ($this->column['user'] == 2){
        $users = $this->_getMeta($id, 'contributor');
        $this->page['user'] = join(', ', $users);
      } else {
        $this->page['user'] = $this->_getMeta($id, 'creator');
      }
    }
    if (!$this->page['user']){
      $this->doc .= '<td class="user">&nbsp;</td>';
      return false;
    } else {
      $this->doc .= '<td class="user">'.hsc($this->page['user']).'</td>';
      return true;
    }
  }
  
  /**
   * Description - (truncated) auto abstract if not set otherwise
   */
  function _descCell($id){
    if (!$this->page['desc']) $this->_getMeta($id, array('description', 'abstract'));
    if (!$this->page['desc']){
      $this->doc .= '<td class="desc">&nbsp;</td>';
      return false;
    } else {
      $max = $this->column['desc'];
      if (($max > 1) && (strlen($desc) > $max)) $desc = substr($desc, 0, $max).'…';
      $this->doc .= '<td class="desc">'.hsc($desc).'</td>';
      return true;
    }
  }

  /**
   * Plugins - respective plugins must be installed!
   */
  function _pluginCell($plug, $col, $id){
    if (!isset($this->page[$col])) $this->page[$col] = $this->$plug->td($id);
    if (!isset($this->page[$col])){
      $this->doc .= '<td class="'.$col.'">&nbsp;</td>';
      return false;
    }
    $this->doc .= '<td class="'.$col.'">'.$this->page[$col].'</td>';
    return true;
  }
  
  
  /**
   * Get default value for an unset element
   */
  function _getMeta($id, $key){
    if (!isset($this->_meta)) $this->_meta = p_get_metadata($id);
    if (is_array($key)) return $this->_meta[$key[0]][$key[1]];
    else return $this->_meta[$key];
  }
        
}
  
//Setup VIM: ex: et ts=4 enc=utf-8 :
