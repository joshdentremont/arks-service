<html>
<head>
  <style>
    .loader{
      position: fixed;
      left: 0px;
      top: 0px;
      width: 100%;
      height: 100%;
      z-index: -1;
      background: url('/front/images/loading.gif')
      50% 50% no-repeat rgb(249,249,249);
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
<!--<div class="loader"></div>-->
</body>
</html>

<?php
require_once 'config/MysqlArkConf.php';

use Noid\Config\MysqlArkConf;

if (strpos($_SERVER['REQUEST_URI'], "/ark:/") === 0 || strpos($_SERVER['REQUEST_URI'], "/ark:") === 0) {
  // processing the Ark URL
  $params = str_replace("ark:/", "", $_GET['q']);
  $parts = array_filter(explode("/", $params));
  $parts_count = count($parts);
  // get Ark ID
  $arkid = $parts[0] . '/'. $parts[1];
  
  if (strpos($arkid, "ark:") === 0) {
    $arkid = str_replace("ark:", "", $arkid);
    $naan = explode("/",$arkid)[0];
  }
  
  // get all database Ark related
  $arkdbs = showArkDatabases();
  // only proceed if already have ark_core db
  if (is_array($arkdbs) && count($arkdbs) > 0) {
    $url = "/404.php";

    // loop through database and find matching one with prefix
    foreach ($arkdbs as $db) {
      $qualifier = "";

      // if there is no arks ID, only NAAN, ie. /ark:61220/
      if ($parts_count == 1) {
        $url = "https://n2t.net/ark:/" . $naan; 
        break;
      }
      // if ark ID found, look for URL fields first.
      // if there is Qualifier, look up for the qualifier, ignore the ark id
      else if ($parts_count > 2) {
        // establish qualifier from Ark URL
        $total = count($parts);
        $qualifier = "/";
        for ($i = 2; $i < $total; $i++) {
          
          $qualifier .= $parts[$i];
          if ($i != $total - 1) {
            $qualifier .= "/";
          }

          if (strpos($parts[$i], ".") !== false) {
            // Qualifier has variants 
            $qualifier_parts = explode(".", $qualifier);
            $hiarachy = $qualifier_parts[0];
            array_shift($qualifier_parts);
            $qualifier =  $hiarachy . "\t" . "." . implode(".",$qualifier_parts);
          }
        }
        // looking up
        $result = lookup($db, $arkid, $qualifier, "URL");
        if (!empty($result)) {
          $url = $result;
          // add a counter here
          increase_reidrection($db, $arkid, $qualifier);
          break;
        }
      }
      // if there is only Arks ID in URL, just lookup based on the arkID
      else if ($parts_count == 2) {
        // do the lookup
        $result = lookup($db, $arkid, $qualifier, "URL");
        
        // if found the results, 
        if (!empty($result)) {
          // found URL field bound associated with the ark id
          $url = $result;
          // add a counter here
          increase_reidrection($db, $arkid, $qualifier);
          break;
        }
      }
      else {
        
      }
    }
   
    // exclusive for UTSC, may removed
    if ($url === "/404.php") {
      // not found URL, get PID and established the URL
      foreach ($arkdbs as $db) {
        // if ark ID found, look for URL fields first.
        $pid = lookup($db, $arkid, "PID");
        if (!empty($pid)) {
          // found URL field bound associated with the ark id
          $dns = getNAA($db);
          $url = "https://$dns/islandora/object/" . $pid;

          // TODO: add a counter here
          increase_reidrection($db, $arkid, $qualifier);
          break;
        }
      }
    }
    // New: Add a check for ? or ?? and the end of Ark URL
    if ( substr_compare($_SERVER['REQUEST_URI'], "?", -1) === 0 ) {
      // if the Ark URLs ends with '?'
      $medata = getMetdata($db, $arkid, $qualifier);
      print($medata);
    }
    if ( substr_compare($_SERVER['REQUEST_URI'], "??", -2) === 0 ) {
      $medata = getMetdata($db, $arkid, $qualifier,"??");
      print($medata);
    }

    if ( substr_compare($_SERVER['REQUEST_URI'], "?", -1) !== 0 ) {
      print("Redirecting to " . $url);
      //header("Location: $url");
    }
  }
} else {
  print "invalid argument";
}

/**
 * Get full metadata
 */
function getMetdata($db, $ark_id, $qualifier,$type=null)
{
  $link = mysqli_connect(MysqlArkConf::$mysql_host, MysqlArkConf::$mysql_user, MysqlArkConf::$mysql_passwd, MysqlArkConf::$mysql_dbname);

  if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
  }

  if (isset($type) && $type == "??") {  // for ??
    $label = "erc-support:";
    if (isset($qualifier) && !empty($qualifier)) { 
      $where = 'WHERE _key regexp "(^|[[:space:]])'.$ark_id.'([[:space:]])'.$qualifier.'([[:space:]])[^\.].*\\\\?\\\\?.*"';
    }
    else {
      // Query data with Ark ID and field name with ?? attached to it without qualifer
      $where = 'WHERE _key regexp "(^|[[:space:]])'.$ark_id. '([[:space:]])[^/].*\\\\?\\\\?.*"';
    }
    
  }
  else { // for ?
    $label = "erc:";
    if (isset($qualifier) && !empty($qualifier)) { 
      $where = 'WHERE _key regexp "(^|[[:space:]])'.$ark_id. '([[:space:]])'.$qualifier.'([[:space:]])[^\.]" AND _key NOT REGEXP ".*\\\\?\\\\?.*"';
    }
    else {
      // Query data with Ark ID and field name with ? attached to it without qualifer.
      $where = 'WHERE _key regexp "(^|[[:space:]])'.$ark_id. '([[:space:]])[^/]" AND _key NOT REGEXP ".*\\\\?\\\\?.*"';
    }
    
  }
  if ($query = mysqli_query($link, "SELECT *  FROM `$db` ". $where)) {

    if (!mysqli_query($link, "SET @a:='this will not work'")) {
      printf("Error: %s\n", mysqli_error($query));
    }
    $results = $query->fetch_all();
    
    if (count($results) > 0) {
      $medata = "<pre>";
      $medata .= $label. "\n";
      foreach($results as $pair) {
        $field = trim(str_replace($ark_id, " ", $pair[0])) ;
        $field = trim(str_replace("?", "", $field));
        if (!in_array($field, [':/c', ":/h", "REDIRECT", ""])) {
          $medata .= $field. ": " . $pair[1] . "\n";
        }
      }
      $medata .= "</pre>";
      return $medata;
    }

    $query->close();
  }
  mysqli_close($link);
  return false;
}

/**
 *  Counting redirection
 */
function increase_reidrection($db, $ark_id, $qualifier) {
  
// TODO UPDATE REDIRECTION COUNT HERE
  $link = mysqli_connect(MysqlArkConf::$mysql_host, MysqlArkConf::$mysql_user, MysqlArkConf::$mysql_passwd, MysqlArkConf::$mysql_dbname);

  if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
  }
  
  // get existed redirection count.
  $count = lookup($db, $ark_id, $qualifier, "REDIRECT");
  if ($count == false) { 
    $count = 1;
    // do insert
    if (empty($qualifier)) {
      $query = 'INSERT INTO `'. $db .'` (_key, _value) VALUES("'.$ark_id. "	REDIRECT". '", '.$count .')';
    }
    else {
      $query = 'INSERT INTO `'. $db .'` (_key, _value) VALUES("'.$ark_id. "	$qualifier	REDIRECT". '", '.$count .')';
    }
  }
  else {
    if (empty($qualifier)) {
      $where = 'WHERE _key regexp "(^|[[:space:]])'.$ark_id.'([[:space:]])REDIRECT$"';
    }
    else {
      $where = 'WHERE _key regexp "(^|[[:space:]])'.$ark_id.'([[:space:]])'.$qualifier.'([[:space:]])REDIRECT$"';
    }
    $count++;
    // do update
    $query = "UPDATE `$db` SET _value = $count ". $where;
  }
  $count++;
  if (mysqli_query($link, $query)) {
    //echo "Count ++";
  }
  else {
   //echo "Counter not changed";
  }
  mysqli_close($link);
}

/**
 * Get Org registered info
 */
function lookup($db, $ark_id, $qualifier,$field = "")
{
  $link = mysqli_connect(MysqlArkConf::$mysql_host, MysqlArkConf::$mysql_user, MysqlArkConf::$mysql_passwd, MysqlArkConf::$mysql_dbname);

  if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
  }
  //$where = 'where _key regexp "(^|[[:space:]])'.$ark_id.'([[:space:]])'.$field.'$"';
  if (empty($qualifier)) { 
    $where = 'where _key regexp "(^|[[:space:]])'.$ark_id.'([[:space:]])'.$field.'$"';
  }
  else {
    $where = "where _key REGEXP '^".$ark_id . "\t" . $qualifier . "\t".$field."'";
  }
  
  if ($query = mysqli_query($link, "SELECT  * FROM `$db` ". $where)) {

    if (!mysqli_query($link, "SET @a:='this will not work'")) {
      printf("Error: %s\n", mysqli_error($query));
    }
    $results = $query->fetch_all();
    
    if (count($results) > 0) {
      return $results[0][1];
    }

    $query->close();
  }
  mysqli_close($link);
  return false;
}

function getNAA($db) {
  $link = mysqli_connect(MysqlArkConf::$mysql_host, MysqlArkConf::$mysql_user, MysqlArkConf::$mysql_passwd, MysqlArkConf::$mysql_dbname);

  if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
  }
  $where = "where _key = ':/naa'";


  if ($query = mysqli_query($link, "SELECT * FROM `$db` ". $where)) {

    if (!mysqli_query($link, "SET @a:='this will not work'")) {
      printf("Error: %s\n", mysqli_error($query));
    }
    $results = $query->fetch_all();
    if (count($results) > 0) {
      return $results[0][1];
    }

    $query->close();
  }
  mysqli_close($link);
  return false;
}

/**
 * list all Arrk database
 * @param string $name
 */
function showArkDatabases()
{
  $link = mysqli_connect(MysqlArkConf::$mysql_host, MysqlArkConf::$mysql_user, MysqlArkConf::$mysql_passwd, MysqlArkConf::$mysql_dbname);

  if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
  }

  if ($query = mysqli_query($link, "SHOW TABLES")) {
    if (!mysqli_query($link, "SET @a:='this will not work'")) {
      printf("Error: %s\n", mysqli_error($query));
    }
    $results = $query->fetch_all();
    $arkdbs = [];
    foreach ($results as $db) {
      // It starts with 'http'
      if (!in_array($db[0], ['system', 'user'])) {
        array_push($arkdbs, $db[0]);
      }
    }
    $query->close();
  }
  mysqli_close($link);
  return $arkdbs;
}