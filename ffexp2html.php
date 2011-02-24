<?php
/**
 * FFExp2HTML.php - Converts the output of ffexp.php in HTML - Version 1.0 (2010-22-12)
 * Created by Claudio Cicali - <claudio.cicali@gmail.com>
 * Released under the MIT license
 *
 * This script reads the JSON file created by ffexp.php >= 1.1 and creates
 * a fully functional (with CSSand a little JavaScript) HTML page
 *
 * As for ffexp.php you need to set the username and the media directory
 * (this is needed to create the correct images and file url)
 *
 * The HTML file will not use external resources (needed images, css and js are all self contained).
 *
 * This script does not need an active network connection
 *
 */ 

/********************************
* Begin of configuration options
*/
 
# Your friendfeed username
$username = "";

# The directory where images and files are stored
$media_dir = "ff_media";

/* Set a limit to the number of processed entries. "0" means "no limit" */
$limit = 0;

/**
 * End of configuration options
 *******************************/

if (empty($username)) {
  notify("You need to provide the script with your Friendfeed username.\n");
  exit;
}

$media_dir .= "/{$username}";

$file = @$argv[1];

if (empty($file) || !is_readable($file)) {
  notify("Please specify a readable file in JSON format\n");
  exit();
}

$fh = fopen($file,"r");
$row = fgets($fh);

if ($row != "[\n") {
  notify("The file is not in the correct format. You should have created the export file with ffexp version 1.1 or higher.\n");
  exit();
}

# Let's go fancy
print "<!doctype html>\n";

$head = $body = "";

$head  = html("meta", null, array('charset' => 'utf-8'));
# Give a chance to the dumbest too
$head .= html("meta", null, array('http-equiv' => "Content-Type", 'content' => "text/html;charset=utf-8"));
$head .= html('style', get_css(), array('type'=>'text/css')); 

$n = 0;
while ($row = fgets($fh)) {
  $row = rtrim($row);
  if (substr($row, -1) == ',') {
    $row = substr($row, 0, -1);
  }
  
  $entry = json_decode($row);

  if ($entry) {
    
    $n++;
    
    if ($limit && ($n == ($limit + 1))) {
      break;
    }

    $comments = '';
    if (isset($entry->comments)) {
      foreach ($entry->comments as $comment) {
        $comments .= html('li', $comment->body . ' &ndash; ' . html('a', $comment->from->name, array('class' => 'e_person' , 'href' => "http://friendfeed.com/{$comment->from->id}")),
          array('class' => 'e_comment'));
      }
      $comments = html('ol', $comments, array('class' => 'e_comments')); 
    }
    
    $likes = '';
    if (isset($entry->likes)) {
      $likes = array();
      foreach ($entry->likes as $like) {
        $likes[] = html('span', html('a', $like->from->name, array('class' => 'e_person' , 'href' => "http://friendfeed.com/{$like->from->id}")),
          array('class' => 'e_like'));
      }
      $likes = html('p', join(', ', $likes), array('class' => 'e_likes'));
    }
    
    $thumbnails = '';
    if (isset($entry->thumbnails)) {
      $thumbnails = array();
      foreach ($entry->thumbnails as $idx => $thumbnail) {
        $src = get_img_src(str_replace('e/', 't_', $entry->id) . "." . ($idx + 1));
        if (FALSE !== strpos($thumbnail->link, '/m.friendfeed-media.com/')) {
          $href = get_img_src(str_replace('e/', 'i_', $entry->id) . "." . ($idx + 1));
        } else {
          $href = $thumbnail->link;
        }
        $thumbnails[] = html('a', html('img', null, array('src' => $src, 'class' => 'e_tn')), array('href' => $href));
      }
      if (count($thumbnails) > 1) {
        $thumbnails[] = html('span', null, array('class' => 'e_more'));
      }
      $thumbnails = html('p', join(' ', $thumbnails), array('class' => 'e_thumbnails'));
    }
    
    $files = '';
    if (isset($entry->files)) {
      $files = array();
      foreach ($entry->files as $idx => $file) {
        $href = get_file_href(str_replace('e/', 'f_', $entry->id) . "." . $file->name);
        $files[] = html('li', html('a', $file->name, array('href' => $href)), array('class' => 'e_file'));
      }
      $files = html('ul', join('', $files), array('class' => 'e_files'));
    }
    
    $via = '';
    if (isset($entry->via)) {
      $via = 'from ' . html('a', $entry->via->name, array('href' => $entry->via->url, 'class' => 'e_via')); 
    }

    $nc = (empty($comments) ? '0' : count($entry->comments));
    $nl = (empty($likes)    ? '0' : count($entry->likes));
    
    $body .= html('li',
               html('p', $entry->body, array('class' => 'e_body')) .
               html('p', 
                 html('span',  html('a', $entry->date, array('class' => 'e_url', 'href' => $entry->url)), array('class' => 'e_date')) .
                 $via .
                 ' &ndash; ' .
                 html('span', "{$nc} comment" . ((!$nc || $nc > 1) ? 's' : ''), array('class' => 'e_nc ' . (empty($comments) ? '' : 'open_comments'))) .
                 ' &ndash; ' .
                 html('span', "{$nl} like" . ((!$nl || $nl > 1) ? 's' : ''), array('class' => 'e_nl ' . (empty($likes) ? '' : 'open_likes')))
                 , array('class' => 'e_meta')
               ) .
               $files .
               $thumbnails .
               $likes .
               $comments
             );
  }
}

$limit_notice = '';
if ($limit) {
  $limit_notice = "(only the first {$limit} are shown)";
}

print html("html", 
        html('head', $head) .
        html('body',
          html('div', 
            html('p', "{$username}'s {$n} Friendfeed posts {$limit_notice}") .
            html('p', 
              html('span', 'Show all comments', array('class' => 'open_comments')) . 
              ' &ndash; ' .
              html('span', 'Show all likes', array('class' => 'open_likes')) . 
              ' &ndash; ' .
              html('span', 'Show all images', array('class' => 'e_more')) 
              , array('id' => 'ff_tools')) .
            html('ul', $body, array('id' => 'ff_entries')), array('id' => 'ff_main')) .
          html('script', get_js())
        )
      );

fclose($fh);

exit();

function html($tag, $content=NULL, $attr=array()) {
  $output = "<{$tag}";
  if (!empty($attr)) {
    $attrs=array();
    foreach($attr as $k => $v) {
      $attrs[] = "{$k}=\"" . htmlspecialchars($v) . "\"";
    }
    $output .= " " . join(' ', $attrs);
  }
  $output .= ">";
  
  if (NULL !== $content) {
    $output .= "{$content}</{$tag}>\n";
  } else {
    $output .= "\n";
  }
  
  return $output;
}

function get_img_src($filename, $fuzzy=TRUE) {
  global $media_dir;
  $candidates = glob("{$media_dir}/{$filename}" . ($fuzzy ? ".*" : ""));
  return $candidates ? $candidates[0] : '';
}

function get_file_href($filename) {
  global $media_dir;
  return "{$media_dir}/{$filename}";
}

function notify($m) {
  file_put_contents("php://stderr", $m);
  flush();
}

function get_css() {
  
  return <<<EOCSS
  
  body {
    font:13px/1.4 arial,helvetica,clean,sans-serif;
    background-color: #f4f4f4;
  }
  
  #ff_main {
    background-color: white;
    width: 730px;
    margin: 0 auto;
    padding: 10px;
    color: #666;
    border: 2px solid #eaeaea;
  }
  
  ul#ff_entries {
    margin: 0;
    padding: 0;
  }
  
  ul#ff_entries > li {
    list-style-type: none;
    margin-bottom: 10px;
    border-bottom: 1px solid silver;
  }

  .e_tn {
    border: 1px solid white;
    margin-right: 5px;
  }
  
  a .e_tn:hover {
    border: 1px solid silver;
  }
  
  p {
    margin: 0 0 5px 0;
  }
  
  p.e_body {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 5px;
  }
  p.e_body a {
    font-weight: normal;
    color: #666;
  }  
  span.e_date {
    color: gray;
  }
  
  a.e_url {
    color: gray;
  }
 
  #ff_tools .e_more,
  span.open_comments,
  span.open_likes {
    color: blue;
    cursor: pointer;
  }
  
  a.e_person {
    text-decoration: none;
  }
  
  #ff_tools,
  .e_comments,
  .e_likes {
    display: none;
  }

  #ff_tools {
    border-bottom: 1px solid silver;
  }
  
  .e_comment {
    margin-bottom: 5px;
  }
  
  .e_comments {
    margin: 0;
    padding: 0;
  }
  
  .e_likes {
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAnVJREFUeNqkk89LFGEYx7/vzLrq7raullAEykYUGVJR7HqRjSiSpIMeuniyWx0XvGT9BUL3TkURHQJFSDCKDTskGCFhYf5qXVfQcG3NVtfZd955+85oWyR2qIHheWbm/X7e7/M+zwitNf7nEn8C7FcixFdJ7aDNcRBzPzMfYz7MeHffVV3YE0Bxgovui4aeqIjEoP11VCvoYg4qPw5rpi9NUHdthx7ZBbBTokNHEv3GwS7oQA3U5ic41iq0vcVVVTCqjkJIA9bCU1hfRjrrr+mBMkCmRB1Dxmx+EnKMdaitNCC/w7EtGtjahjgaorIBZmUzvo1eL9BJ46Eu/dVwKbSdNBp7Q9rPdRbF9gZSgzk+lABVwusXTGUB9voElDWHwInekKOQdLUegLR2URuHw521XSRQ4vyVai9qJdGaKDCnGxey9g6+yDEQ0P47oAmVYVpeK4u8aJeYl3Zy6eWORWewoBSiZQBLKHp1KhsvB95gfnpxW6jkTrSQmVtF6vmiBwMPjJuavxwopCGLzPxoSRzHzMcssnPLntAVLKTzmJ3KIxYPQxgBrvdBWph1tb6dEoZkbvy0EWlAILiEltYoJt7OY/J9lq4kgtUCsVgNggETvvApFHMZyBKGym3M94t6Qj6H4o9CjpyCKkyyATwPttGxf7YRMIOchfBlZJ7dKPBIjpy5pVe8Emo79Yo7YZuzD1nfYZjhOIRZR/va67/hP4CK+oscsAtY/TDo7t7tineN8tJjcYnncS/Y1BP17z8Jo4J8x4ZtKWwsTWN5tC/NnW+eva2H9/yZsg+EOyQ9dNTGVsXYSVA0xnuYd9+5O3/5mf7l+iHAAP3UjrcWL0PwAAAAAElFTkSuQmCC);
    background-repeat: no-repeat;
    padding-left: 20px;
  }
  
  .e_comment {
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAXRJREFUeNpi/P//PwMlgImBQsACY0R27BQCUpOBOASI2fDo2Q3EWcsr3O+AeSAvgHBE+46NKw/e+v/7z9//+MCWk/f/A9XeBmI2kD4WqO08QMo7xFaF4fMPBoaff/4z/AUGzb9/DGD6L4j+95+BEaTITIHh+PUXKneffzQAck/BvCAkwsfBzMTIyPDj93+Gf/+RNIIN+s/w5x/EQBDg4wL7UBQlDGAApvEfks0IV+COhXdvPv0AqgX6iQmi8A/YVojNMAwMMbDiLz9+g/XADQCG6BcgtXXN4TsMIsDQUBBmZFAWYWJQE2Ni4GFnZPj7l4FBko+RQU+amWHrqQcMt59+AMXABZBeRlhCQorGcCBmBom5GsoyJLlrgeU/fPnJsO7oXYbd5x9fAXIDMaIRGwZG1Z3n777+n7vjKijqvgNxLboaRlxJGeiiXkd9maL9F598BXJrgTb2Y1OH1QCgZiUgtQeI24Aa5+BLyowDnpkoNgAgwACtnv0bwuZFOAAAAABJRU5ErkJggg%3D%3D);
    background-repeat: no-repeat;
    padding-left: 20px;
    list-style-type: none;
  }
  
  #ff_entries .e_more {
    display: inline-block;
    cursor: pointer;
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAAJYSURBVHjanFRLTxNRFD4zfdFhtNjII0VNgQbjRrEx1YUuEHGhLgy6gJWuhBj/AhqDe3fGGDe40Shx4WM1mGoiUdFoWFhj01TSlAqIpa3ttNOZtp5zuVNaU5PCl3wzc++533fPmfsQhq6/g38gIc8hB5EBpI/3R5DzyCDyBVKtFVnpMXvzGGucuvF+2GETJ3o7pRFvhwQetwN2yXYWW88W/Ymk5l9cVSeiK+pTTS/fRZ3CdRtGvDGGBlP7u+W+vi6pOlOhWGJvp90C1E/0Lqsj35eyh1AziWYPKS7w0ob3tTvvHO7Z6XO12qAZpHM6fPmRicR+5a9iU6GMJKtFGO90OXyCIEBGNZoyorGkSSQL40apMifSj3XL9guy0wJpVWc8e6QDrp3x4mAAt2xj30MHd1fjJklDWvIgo8EWu8gyMflqYY3Nej7QBQuLGfgQTsGBPTJj7TgiacmDSgvgxPAnv1nS52gavsWzTDjQ44IHr+NwtL8NTg+0w/OPK7CU1KBSqdRWGiA7X9EoQ65QwiXWYTmlQWwtD9PBOBsxdsKDM+vw8tMqyC1WuHxyL5TLFSAfk+TBlj+VM8DAoFEqmwE22MQOp7Xhdy0oo0ge94pubJoQRjETwn0lxsqmBaD37WfRRj4RkW/7Ovh7XYzhRA4evU3A6PHuDdPZGPxc1xoZzYv87NTBzGbqcRj6Pa0smzdff7P/9B8EaWfTeZhGXoTtYQZ5SeSn+B4ytA2TENeqIu+gU3xri2YhrlGq1wgHnWLa0leaKHOGZ6LU3Uc1oMAc8slWL7a/AgwAc7UDNrBJ/NQAAAAASUVORK5CYII%3D);  
    background-repeat: no-repeat;
    width: 18px;
    height: 18px;
  }
  
  .e_files {
    margin-bottom: 5px;
  }

  .e_thumbnails > a {
    display: none;
  }
  
  .e_thumbnails.open > a,
  .e_thumbnails > a:first-child {
    display: inline;
  }
  
EOCSS;
}

function get_js() {
  return <<<EOJS

  var entries = document.getElementById('ff_entries')
    , tools = document.getElementById('ff_tools')
    , block
    , elements
    , i
    , test = ['comments', 'likes'];
  
  entries.addEventListener('click', function(ev) {
    for (i=0; i < 2; i++) {
      if (-1 != ev.target.className.indexOf("open_" + test[i])) {
        if (block = ev.target.parentNode.parentNode.getElementsByClassName('e_' + test[i])) {
          block[0].style.display = 'block';
        }
      }
    } 
    if (ev.target.className == 'e_more') {
      ev.target.style.display = "none";
      ev.target.parentNode.className += " open";
    }
  }, false);
  
  tools.addEventListener('click', function(ev) {
    for (i=0; i < 2; i++) {
      if (-1 != ev.target.className.indexOf("open_" + test[i])) {
        elements = entries.getElementsByClassName('e_' + test[i]);
        for (var n=0; n < elements.length; n++) {
          elements[n].style.display = 'block';
        }
      }
    }
    if (ev.target.className == 'e_more') {
      elements = entries.getElementsByClassName('e_thumbnails');
      for (i=0; i < elements.length; i++) {
        elements[i].className += " open";
      }
      elements = entries.getElementsByClassName('e_more');
      for (i=0; i < elements.length; i++) {
        elements[i].style.display = 'none';
      }
    }
    
  }, false);
  
  tools.style.display = 'block';
  
EOJS;
}

