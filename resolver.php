<?php
require_once "functions.php";

require_once 'NoidLib/custom/MysqlArkConf.php';
use Noid\Lib\Custom\MysqlArkConf;
use Noid\Lib\Custom\Database;

if (strpos($_SERVER['REQUEST_URI'], "/ark:/") === 0) {
  $uid = str_replace("ark:/", "", $_GET['q']);

  // get all database Ark related
  $arkdbs = Database::showArkDatabases();
  // only proceed if already have ark_core db
  if (is_array($arkdbs) && count($arkdbs) > 0) {
    $url = "/404.php";

    // loop through database and find matching one with prefix
    foreach ($arkdbs as $db) {
      // if ark ID found, look for URL fields first.
      $result = lookup($db, $uid, "URL");
      if (!empty($result)) {
        // found URL field bound associated with the ark id
        $url = $result;
        break;
      }
    }
    // exclusive for UTSC, may removed
    if ($url === "/404.php") {
      // not found URL, get PID and established the URL
      foreach ($arkdbs as $db) {
        // if ark ID found, look for URL fields first.
        $pid = lookup($db, $uid, "PID");
        if (!empty($pid)) {
          // found URL field bound associated with the ark id
          $dns = trim(getNAA($db), '"');
          $url = "https://$dns/islandora/object/" . $pid;
          break;
        }
      }
    }
    header("Location: $url");
  }
} else {
  print "invalid argument";
}

/**
 * Get Org registered info
 */
function lookup($db, $ark_id, $field = "")
{
  $link = mysqli_connect(MysqlArkConf::$mysql_host, MysqlArkConf::$mysql_user, MysqlArkConf::$mysql_passwd, MysqlArkConf::$mysql_dbname);

  if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
  }
  $where = 'where _key regexp "(^|[[:space:]])'.$ark_id.'([[:space:]])'.$field.'$"';

  if ($query = mysqli_query($link, "SELECT  _value FROM `$db` ". $where)) {

    if (!mysqli_query($link, "SET @a:='this will not work'")) {
      printf("Error: %s\n", mysqli_error($query));
    }
    $results = $query->fetch_all();

    if (count($results) > 0) {
      return $results[0][0];
    }

    $query->close();
  }
  mysqli_close($link);
  return false;
}

