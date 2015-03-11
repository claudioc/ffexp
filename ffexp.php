<?php
/**
 * FFExp.php - A friendfeed exporter script - Version 1.2 (2011-01-07)
 *
 * Created by Claudio Cicali - <claudio.cicali@gmail.com>
 * Released under the MIT license
 *
 * This script attempts to download your Friendfeed stream.
 * The script is also able to download a specific stream (e.g. user's likes) as defined in the configuration options.
 *
 * The output is a JSON formatted file the you could use as an input for other programs.
 * The stream is composed of a list of "entry" (your posts). Each entry has the list of comments and likes attached.
 * 
 * Run it like "php ffexp.php my_ff_stream.json"
 *
 * If the my_ff_stream.json (or whatever name you choose) file already exists,
 * it will be used to know the latest entry already fetched and break the download 
 * as soon as it will be reached (sort of incremental backup). Side effect: should 
 * some comments or likes have been added to already fetched entries, they will NOT
 * be read (comments and likes travel with the parent entry - as a whole).
 * You always need a FULL export to be sure to have, well, the FULL and updated export :)
 *
 * The script will also download the images and the files that could have
 * been attached to each post (already downloaded assets will not be downloaded again).
 * 
 * The first lines of this scripts contain some basic configuration
 * options, like your Friendfeed username, the remote_key (if your stream is
 * private) and the directory where the images and files will be downloaded into.
 * 
 * You'll be notified every 100 posts, and every file or image downloaded
 * 
 * Images and files are saved using a naming convention that could subsequently
 * help on rebuilding the post <-> attachments relationship. Each image has
 * an "i_" prefix, a "t_" for the thumbnails and an "f_" for the files. 
 * After the prefix, the entry unique identifier is added (it's something like
 * an hash). For files, the original file name is added too.
 *
 * Limits and bugs:
 *
 * Exporting filter/discussion (Entries the authenticated user has commented 
 * on or liked) and filter/direct (direct messages) seems not to work.
 *
 * When you'll have the JSON export file you can use ffexp2html to render it as HTML
 *
 * For Friendfeed API documentation See http://friendfeed.com/api/documentation
 *
 * For this script ChangeLog look at the bottom of this very file
 *
 */

/********************************
 * Begin of configuration options
 */
 
# Your friendfeed username
$username = "";

# Leave empty if your stream is public, or get 
# your remote key here http://friendfeed.com/remotekey
$remote_key = "";

# The stream you want to export.
# Leave empty if you want to export just your stream.
# You may like to export:
# - your discussions  "filter/discussions" (requires remote_key)
# - your likes        "username/likes" (requires remote_key if username has a private feed, username is your username)
# - a group feed      "groupname" (requires remote_key if group is private)
# - a list            "list/listname" (requires remote_key)
# See Friendfeed API Documentation for feeds http://friendfeed.com/api/documentation#feeds for further info
$stream = "";

# The directory where images and files will be downloaded (defaults to
# the subdirectory "ff_media" just below the the directory where the script is executed)
$media_dir = "./ff_media";

# Use FALSE to not download assets
$download_images = TRUE;
$download_files = TRUE;

/* Number of pages to retrieve - "0" means "no limit" (each page is 100 entries big) */
$max_pages = 0;

/**
 * End of configuration options
 *******************************/

ini_set('memory_limit', "512M");

if (empty($username)) {
  notify("You need to provide the script with your Friendfeed username.\n");
  exit;
}

if (!extension_loaded('curl')) {
  notify("Sorry, but this script needs the cURL PHP extension to run.\n");
  exit;
}

if (!function_exists('json_encode')) {
  notify("Sorry, but this script needs at least PHP 5.2 to run (for JSON).\n");
  exit;
}

$fh = NULL;
$file = @$argv[1];

if (empty($file)) {
  notify("You need to pass this script the name of the output file.\n");
  exit();
}

$fh = fopen($file, ($append = file_exists($file)) ? "r+" : "w+");

if (!$fh) {
  notify("Sorry: the specified file cannot be opened.\n");
  exit();
}

$file_tmp = $file . ".tmp";
$fh_tmp = fopen($file_tmp, "w+");

$last_entry = NULL;
if ($append) {
  $stat = fstat($fh);
  if ($stat['size'] > 0) {
    $row = fgets($fh);
    if ($row != "[\n") {
      notify("The file is not in the correct format. You should have created the export file with ffexp version 1.1 or higher.\n");
      @unlink($file_tmp);
      exit();
    }
    $last_row = rtrim(fgets($fh));
    if (substr($last_row, -1) == ',') {
      $last_row = substr($last_row, 0, -1);
    }
    $last_entry = json_decode($last_row);
  }
}

if ($append && $last_entry) {
  notify("Reading entries after {$last_entry->id} ({$last_entry->date})\n");
}

if ($max_pages) {
  notify("Page limit set to {$max_pages}.\n");
}

if ($download_images || $download_files) {
  @mkdir($media_dir);
  $media_dir .= "/{$username}";
  @mkdir($media_dir);

  if (!is_writable($media_dir)) {
    notify("The specified media path is not writable\n");
    @unlink($file_tmp);
    exit;
  }
}

$ch = curl_init();
$options = array
(
  CURLOPT_HEADER          => false,
  CURLOPT_RETURNTRANSFER  => true,
  CURLOPT_SSL_VERIFYPEER  => false,
  CURLOPT_SSL_VERIFYHOST  => false,
  CURLOPT_FOLLOWLOCATION  => true,
  CURLOPT_USERAGENT       => "Friendfeed exporter script by Claudio Cicali",
);

if (!empty($remote_key)) {
  $options[CURLOPT_USERPWD] = "{$username}:{$remote_key}";
}

curl_setopt_array($ch,$options);

/* This seems the limit anyway */
$items_per_page = 100;

$qs = array(
  "pretty"      => 1,
  "start"       => 0,
  "num"         => $items_per_page,
  "maxcomments" => 10000,
  "maxlikes"    => 10000,
  "raw"         => 1
);

$pages = 0;

$end_export = false;
$export_started = false;

fwrite($fh_tmp, "[\n");

$processed_entries = 0;
if (empty($stream)) {
  $stream = $username;
}
do {
  notify("Fetching page " . ($pages + 1) . "\n");
  $url = "https://friendfeed-api.com/v2/feed/{$stream}?" . http_build_query($qs);
  curl_setopt($ch, CURLOPT_URL, $url);
  $response = curl_exec($ch);

  if ($response === false || curl_errno($ch)) {

    // We got a problem from the API, but the export was already start.
    // Save what we have and say goodbye
    if ($export_started) {
      notify("We got a problem from the API. Maybe we reached the limit. The file is saved anyway.\n");
      break;
    }
  }

  $pages ++;
  
  $data = json_decode($response);
  
  if (!isset($data->entries)) {
    notify("An error occurred. Export aborted.\n");
    notify("Perhaps you mispelled the username or tried to access a private feed? Got a 'limit-exceeded' from Frienfeed?\n");
    @unlink($file_tmp);
    exit;
  }
  $export_started = true;
  $entries = array();
  foreach ($data->entries as $entry) {
    
    if ($last_entry && ($entry->id == $last_entry->id)) {
      $end_export = TRUE;
      break;
    } else {
      if ($download_images) {
        download_images_for($entry);
      }
      if ($download_files) {
        download_files_for($entry);
      }
      $entries[] = json_encode($entry);
    }
  }
  
  if ($pages > 1 && !empty($entries)) {
    fwrite($fh_tmp, ",\n");
  }
  
  fwrite($fh_tmp, join(",\n", $entries));

  $processed_entries += count($entries);
  
  if (!$end_export) {
    $end_export = (($max_pages != 0 && $pages == $max_pages) || (count($data->entries) < $items_per_page));
    $qs['start'] = intval($qs['start']) + $items_per_page;
    sleep(1);
  }
} while (!$end_export);

curl_close($ch);

if ($append && $last_entry) {
  if ($processed_entries > 0) {
    fwrite($fh_tmp, ",\n");
  }
  fwrite($fh_tmp, $last_row . ",\n");
  while ($row = fgets($fh)) {
    fwrite($fh_tmp, $row);
  }
} else {
  fwrite($fh_tmp, "\n]");
}

fclose($fh);
fclose($fh_tmp);

unlink($file);
rename($file_tmp, $file);

@notify("Export terminated.\n");

function notify($m) {
  file_put_contents("php://stderr", $m);
  flush();
}

function image_filename_from_headers($headers) {
  $ma = array();
  if (preg_match('/Content-Disposition: attachment; filename="(.*?)"/', $headers, $ma)>0) {
    return $ma[1];
  }
  return false;
}

function download_images_for(&$entry) {
  global $remote_key, $username;

  if (!isset($entry->thumbnails) || empty($entry->thumbnails)) {
    return;
  }

  $header_data = '';
  $header_func = function($ch, $header) use (&$header_data) {
    $header_data .= $header;
    return strlen($header);
  };

  $options = array
  (
    CURLOPT_HEADER          => false,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_FOLLOWLOCATION  => true,
    CURLOPT_BINARYTRANSFER  => true,
    CURLOPT_USERAGENT       => "Friendfeed exporter script by Claudio Cicali",
  );
  if (!empty($remote_key)) {
    $options[CURLOPT_USERPWD] = "{$username}:{$remote_key}";
  }

  $saved_idx = 1;
  foreach ($entry->thumbnails as &$tn) {
    $i_saved = $t_saved = false;
    
    if (FALSE !== strpos($tn->link, '/m.friendfeed-media.com/')) {
      # Use the ID of the post to build the image name
      $filename = str_replace("e/","i_",$entry->id) . ".{$saved_idx}";
      # "look forward" to see if the asset has already been downloaded
      if (asset_exists($filename)) {
        notify("Image {$filename} already here. Skipping.\n");
        continue;
      }
      $ch = curl_init();
      $header_data = '';
      curl_setopt_array($ch,$options);
      curl_setopt($ch, CURLOPT_URL, $tn->link);
      curl_setopt($ch, CURLOPT_HEADERFUNCTION, $header_func);
      save_image(curl_exec($ch), $filename, curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
      $image_filename = image_filename_from_headers($header_data);
      curl_close ($ch);
      if ($image_filename !== false) {
        $tn->filename = $image_filename;
      }
      $i_saved = true;
    }

    # Thumbnail
    if (FALSE !== strpos($tn->url, '/m.friendfeed-media.com/')) {
      $ch = curl_init();
      curl_setopt_array($ch,$options);
      curl_setopt($ch, CURLOPT_URL, $tn->url);
      $filename = str_replace("e/","t_",$entry->id) . ".{$saved_idx}";
      if (asset_exists($filename)) {
        notify("Image {$filename} already here. Skipping.\n");
      } else {
        save_image(curl_exec($ch), $filename, curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
      }
      curl_close ($ch);
      $t_saved = true;
    }
    unset($tn);
    
    if ($i_saved || $t_saved) {
      $saved_idx++;
    }
  }
}

function asset_exists($filename, $fuzzy=TRUE) {
  global $media_dir;
  $candidates = glob("{$media_dir}/{$filename}" . ($fuzzy ? ".*" : ""));
  return !empty($candidates);
}

function download_files_for($entry) {
  global $remote_key, $username;
  
  if (!isset($entry->files) || empty($entry->files)) {
    return;
  }
  $options = array
  (
    CURLOPT_HEADER          => false,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_FOLLOWLOCATION  => true,
    CURLOPT_BINARYTRANSFER  => true,
    CURLOPT_USERAGENT       => "Friendfeed exporter script by Claudio Cicali",
  );
  
  if (!empty($remote_key)) {
    $options[CURLOPT_USERPWD] = "{$username}:{$remote_key}";
  }
  
  foreach ($entry->files as $file) {
    if (FALSE !== strpos($file->url, '/m.friendfeed-media.com/')) {
      # Use the ID of the post to build the file name
      $filename = str_replace("e/","f_",$entry->id) . ".{$file->name}";
      if (asset_exists($filename, FALSE)) {
        notify("File {$filename} already here. Skipping.\n");
        continue;
      }
      $ch = curl_init();
      curl_setopt_array($ch,$options);
      curl_setopt($ch, CURLOPT_URL, $file->url);
      save_file(curl_exec($ch), $filename);
      curl_close ($ch);
    }
  }
}

function save_image($rawdata, $filename, $mime) {
  global $media_dir;
  if (empty($rawdata)) {
    return;
  }
  switch($mime) {
    case 'image/jpeg':
      $filename .= ".jpg";
      break;
    case 'image/png':
      $filename .= ".png";
      break;
    case 'image/gif':
      $filename .= ".gif";
      break;
    default:
      return;
  }
  notify("Saving image... {$media_dir}/{$filename}\n");
  file_put_contents("{$media_dir}/{$filename}", $rawdata);
  unset($rawdata);
}

function save_file($rawdata, $filename) {
  global $media_dir;
  if (empty($rawdata)) {
    return;
  }
  notify("Saving file... {$media_dir}/{$filename}\n");
  file_put_contents("{$media_dir}/{$filename}", $rawdata);
  unset($rawdata);
}

/*
 * ChangeLog:
 *
 * 1.3 The script is now able to download streams other than the user's own stream 
 *     (e.g. user's comments/likes, a group stream...).
 *
 * 1.2 The script now needs the output file as its (only) parameter. If the file
 *     already exists, it will be used to detect the last entry fetched. This
 *     way subsequent script runs will behave incrementally and not try to download
 *     everything everytime
 *
 * 1.1 every row is now printed as it is read instead of merging an huge array 
 *     and the dumping it at the end of the process (scalability issue)
 *     Added GIF download
 *     Images and files are not downloaded if they are already present
 *
 * 1.0 First public release
 *
 */
