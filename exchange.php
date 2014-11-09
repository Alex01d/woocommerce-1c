<?php
if (!defined('ABSPATH')) exit;

define('WC1C_TIMESTAMP', time());

function wc1c_exchange_init() {
  add_rewrite_rule("wc1c/exchange", "index.php?pagename=wc1c-exchange");
  flush_rewrite_rules();
}
add_action('init', 'wc1c_exchange_init');

function wc1c_wpdb_end($is_commit = false, $no_check = false) {
  global $wpdb, $wc1c_is_transaction;

  if (empty($wc1c_is_transaction)) return;

  $wc1c_is_transaction = false;

  $sql_query = !$is_commit ? "ROLLBACK" : "COMMIT";
  $wpdb->query($sql_query);
  if (!$no_check) wc1c_check_wpdb_error();

  if (defined('WP_DEBUG')) echo "\n" . strtolower($sql_query);
}

function wc1c_error($message, $type = "Error", $no_exit = false) {
  global $wc1c_is_error;

  $wc1c_is_error = true;

  $message = "$type: $message";
  $last_char = substr($message, -1);
  if (!in_array($last_char, array('.', '!', '?'))) $message .= '.';

  error_log($message);
  echo "$message\n";

  if (defined('WP_DEBUG')) {
    echo "\n";
    debug_print_backtrace();
  }

  if (!$no_exit) {
    wc1c_wpdb_end();

    exit;
  }
}

function wc1c_set_strict_mode() {
  error_reporting(-1);
  set_error_handler('wc1c_strict_error_handler');
  set_exception_handler('wc1c_strict_exception_handler');
}

function wc1c_strict_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
  if (error_reporting() === 0) return false;

  switch ($errno) {
    case E_NOTICE:
    case E_USER_NOTICE:
      $type = "Notice";
      break;
    case E_WARNING:
    case E_USER_WARNING:
      $type = "Warning";
      break;
    case E_ERROR:
    case E_USER_ERROR:
      $type = "Fatal Error";
      break;
    default:
      $type = "Unknown Error";
  }

  $message = sprintf("%s in %s on line %d", $errstr, $errfile, $errline);
  wc1c_error($message, "PHP $type");
}

function wc1c_strict_exception_handler($exception) {
  $message = sprintf("%s in %s on line %d", $exception->getMessage(), $exception->getFile(), $exception->getLine());
  wc1c_error($message, "Exception");
}

function wc1c_check_permissions($user) {
  if (!user_can($user, 'shop_manager') && !user_can($user, 'administrator')) wc1c_error("No permissions");
}

function wc1c_wp_error($wp_error, $only_error_code = null) {
  $messages = array();
  foreach ($wp_error->get_error_codes() as $error_code) {
    if ($only_error_code && $error_code != $only_error_code) continue;
    
    $wp_error_messages = implode(", ", $wp_error->get_error_messages($error_code));
    $wp_error_messages = strip_tags($wp_error_messages);
    $messages[] = sprintf("%s: %s", $error_code, $wp_error_messages);
  }

  wc1c_error(implode("; ", $messages), "WP Error");
}

function wc1c_check_wp_error($wp_error) {
  if (is_wp_error($wp_error)) wc1c_wp_error($wp_error);
}

function wc1c_mode_checkauth() {
  if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
  }
  
  if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) wc1c_error("No authentication credentials");

  $user = wp_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
  wc1c_check_wp_error($user);
  wc1c_check_permissions($user);

  $expiration = time() + apply_filters('auth_cookie_expiration', DAY_IN_SECONDS, $user->ID, false);
  $auth_cookie = wp_generate_auth_cookie($user->ID, $expiration);

  exit("success\nwc1c-auth\n$auth_cookie");
}

function wc1c_check_auth() {
  if (!empty($_COOKIE['wc1c-auth'])) {
    $user = wp_validate_auth_cookie($_COOKIE['wc1c-auth'], 'auth');
    if (!$user) wc1c_error("Invalid cookie");
  }
  else {
    $user = wp_get_current_user();
    if (!$user->ID) wc1c_error("Not logged in");
  }

  wc1c_check_permissions($user);

  if (!defined('WP_DEBUG')) define('WP_DEBUG', true);
}

function wc1c_clean_data_dir($type) {
  $data_dir = WC1C_DATA_DIR . $type;
  if (is_dir($data_dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path => $item) {
      if ($item->isDir()) {
        rmdir($path) or wc1c_error(sprintf("Failed to remove directory %s", $path));
      }
      else {
        unlink($path) or wc1c_error(sprintf("Failed to unlink file %s", $path));
      }
    }
  }
  else {
    mkdir($data_dir) or wc1c_error(sprintf("Failed to make directory %s", $data_dir));
  }
}

function wc1c_filesize_to_bytes($filesize) {
  switch (substr($filesize, -1)) {
    case 'G':
    case 'g':
      return (int) $filesize * 1073741824;
    case 'M':
    case 'm':
      return (int) $filesize * 1048576;
    case 'K':
    case 'k':
      return (int) $filesize * 1024;
    default:
      return $filesize;
  }
}

function wc1c_mode_init($type) {
  wc1c_clean_data_dir($type);

  $zip = class_exists('ZipArchive') ? 'yes' : 'no';
  $file_limit = wc1c_filesize_to_bytes(ini_get('post_max_size'));

  exit("zip=$zip\nfile_limit=$file_limit");
}

function wc1c_mode_file($type, $filename) {
  $path = WC1C_DATA_DIR . "$type/" . ltrim($filename, "./\\");
  $path_dir = dirname($path);
  if (!is_dir($path_dir)) mkdir($path_dir, 0777, true) or wc1c_error(sprintf("Failed to create directories for file %s", $filename));

  $dest_fp = fopen($path, 'a') or wc1c_error(sprintf("Failed to open file %s", $filename));
  flock($dest_fp, LOCK_EX) or wc1c_error(sprintf("Failed to lock file %s", $filename));

  $source_fp = fopen("php://input", 'r') or wc1c_error("Failed to open input file");

  while (!feof($source_fp)) {
    if (($data = fread($source_fp, 8192)) === false) wc1c_error("Failed to read from input file");
    if (fwrite($dest_fp, $data) === false) wc1c_error(sprintf("Failed to write to file %s", $filename));
  }

  fflush($dest_fp) or wc1c_error(sprintf("Failed to flush file %s", $filename));
  flock($dest_fp, LOCK_UN) or wc1c_error(sprintf("Failed to unlock file %s", $filename));

  fclose($source_fp) or wc1c_error("Failed to close input file");
  fclose($dest_fp) or wc1c_error(sprintf("Failed to close file %s", $filename));

  if ($type == 'catalog') {
    exit("success");
  }
  elseif ($type == 'sale') {
    wc1c_unpack_files($type);

    $data_dir = WC1C_DATA_DIR . "$type/";
    foreach (glob("{$data_dir}orders-*.xml") as $path) {
      $filename = substr($path, strlen($data_dir));
      wc1c_mode_import($type, $filename);
    }
  }
}

function wc1c_check_wpdb_error() {
  global $wpdb;

  if (!$wpdb->last_error) return;

  wc1c_error(sprintf("%s for query \"%s\"", $wpdb->last_error, $wpdb->last_query), "DB Error", true);

  wc1c_wpdb_end(false, true);

  exit;
}

function wc1c_set_transaction_mode() {
  global $wpdb, $wc1c_is_transaction;

  set_time_limit(0);

  register_shutdown_function('wc1c_transaction_shutdown_function');

  $wpdb->show_errors(false); 

  $wc1c_is_transaction = true;
  $wpdb->query("START TRANSACTION");
  wc1c_check_wpdb_error();
}

function wc1c_transaction_shutdown_function() {
  $error = error_get_last();
  $is_commit = $error['type'] > E_PARSE;

  wc1c_wpdb_end($is_commit);
}

function wc1c_unpack_files($type) {
  if (!class_exists('ZipArchive')) return;

  $data_dir = WC1C_DATA_DIR . $type;
  $zip_paths = glob("$data_dir/*.zip");
  if ($zip_paths === false) wc1c_error("Failed to find archives");
  if (!$zip_paths) return;

  foreach ($zip_paths as $zip_path) {
    $zip = new ZipArchive();
    $result = $zip->open($zip_path);
    if ($result !== true) wc1c_error(sprintf("Failed open archive %s with error code %d", $zip_path, $result));

    $zip->extractTo($data_dir) or wc1c_error(sprintf("Failed to extract from archive %s", $zip_path));
    $zip->close() or wc1c_error(sprintf("Failed to close archive %s", $zip_path));

    unlink($zip_path) or wc1c_error(sprintf("Failed to unlink file %s", $zip_path));
  }

  if ($type == 'catalog') exit("progress");
}

function wc1c_xml_start_element_handler($parser, $name, $attrs) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;

  $wc1c_names[] = $name;
  $wc1c_depth++;

  call_user_func("wc1c_{$wc1c_namespace}_start_element_handler", $wc1c_is_full, $wc1c_names, $wc1c_depth, $name, $attrs);
}

function wc1c_xml_character_data_handler($parser, $data) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;

  $name = $wc1c_names[$wc1c_depth];

  call_user_func("wc1c_{$wc1c_namespace}_character_data_handler", $wc1c_is_full, $wc1c_names, $wc1c_depth, $name, $data);
}

function wc1c_xml_end_element_handler($parser, $name) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;
  
  call_user_func("wc1c_{$wc1c_namespace}_end_element_handler", $wc1c_is_full, $wc1c_names, $wc1c_depth, $name);

  array_pop($wc1c_names);
  $wc1c_depth--;
}

function wc1c_xml_parse($fp) {
  $parser = xml_parser_create();

  xml_set_element_handler($parser, 'wc1c_xml_start_element_handler', 'wc1c_xml_end_element_handler');
  xml_set_character_data_handler($parser, 'wc1c_xml_character_data_handler'); 

  $meta_data = stream_get_meta_data($fp);
  $filename = basename($meta_data['uri']);

  while (!($is_final = feof($fp))) {
    if (($data = fread($fp, 4096)) === false) wc1c_error(sprintf("Failed to read from file %s", $filename));
    if (!xml_parse($parser, $data, $is_final)) {
      $message = sprintf("%s in %s on line %d", xml_error_string(xml_get_error_code($parser)), $filename, xml_get_current_line_number($parser));
      wc1c_error($message, "XML Error");
    }
  }

  xml_parser_free($parser);
}

function wc1c_xml_is_full($fp) {
  $is_full = null;
  while (($buffer = fgets($fp)) !== false) {
    if (strpos($buffer, " СодержитТолькоИзменения=") === false) continue;

    $is_full = strpos($buffer, " СодержитТолькоИзменения=\"false\"") !== false;
    break;
  }

  $meta_data = stream_get_meta_data($fp);
  $filename = basename($meta_data['uri']);

  rewind($fp) or wc1c_error(sprintf("Failed to rewind on file %s", $filename));

  return $is_full;
}

function wc1c_mode_import($type, $filename) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;

  if ($type == 'catalog') wc1c_unpack_files($type);

  $path = WC1C_DATA_DIR . "$type/$filename";
  $fp = fopen($path, 'r') or wc1c_error(sprintf("Failed to open file %s", $filename));
  flock($fp, LOCK_EX) or wc1c_error(sprintf("Failed to lock file %s", $filename));

  wc1c_set_transaction_mode();

  $namespace = pathinfo($filename, PATHINFO_FILENAME);
  list($namespace) = explode('-', $namespace, 2);

  $wc1c_namespace = $namespace;
  $wc1c_is_full = wc1c_xml_is_full($fp);
  $wc1c_names = array();
  $wc1c_depth = -1;

  require_once sprintf(WC1C_PLUGIN_DIR . "exchange/%s.php", $namespace);

  wc1c_xml_parse($fp);

  flock($fp, LOCK_UN) or wc1c_error(sprintf("Failed to unlock file %s", $filename));
  fclose($fp) or wc1c_error(sprintf("Failed to close file %s", $filename));

  exit("success");
}

function wc1c_post_id_by_meta($key, $value) {
  global $wpdb;

  if ($value === null) return;

  $cache_key = "wc1c_post_id_by_meta-$key-$value";
  $post_id = wp_cache_get($cache_key);
  if ($post_id === false) {
    $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta JOIN $wpdb->posts ON post_id = ID WHERE meta_key = %s AND meta_value = %s", $key, $value));
    wc1c_check_wpdb_error();

    if ($post_id) wp_cache_set($cache_key, $post_id);
  }

  return $post_id;
}

function wc1c_mode_query($type) {
  include WC1C_PLUGIN_DIR . "exchange/query.php";

  exit;
}

function wc1c_mode_success($type) {
  exit("success");
}

function wc1c_template_redirect() {
  if (get_query_var('pagename') != 'wc1c-exchange') return;

  header("Content-Type: text/plain; charset=utf-8");

  wc1c_set_strict_mode();

  if (empty($_GET['type'])) wc1c_error("No type");
  if (empty($_GET['mode'])) wc1c_error("No mode");

  if ($_GET['mode'] == 'checkauth') {
    wc1c_mode_checkauth();
  }

  wc1c_check_auth();

  if ($_GET['mode'] == 'init') {
    wc1c_mode_init($_GET['type']);
  }
  elseif ($_GET['mode'] == 'file') {
    wc1c_mode_file($_GET['type'], $_GET['filename']);
  }
  elseif ($_GET['mode'] == 'import') {
    wc1c_mode_import($_GET['type'], $_GET['filename']);
  }
  elseif ($_GET['mode'] == 'query') {
    wc1c_mode_query($_GET['type']);
  }
  elseif ($_GET['mode'] == 'success') {
    wc1c_mode_success($_GET['type']);
  }
  else {
    exit;
  }
}
add_action('template_redirect', 'wc1c_template_redirect');