<?php

require('config.php');

if (!stristr($_SERVER["CONTENT_TYPE"], "application/soap+xml")) {
  error_log("spp.php - Unexpected Content-Type " . $_SERVER["CONTENT_TYPE"]);
  die("Unexpected Content-Type");
}

if ($_SERVER["REQUEST_METHOD"] != "POST") {
  error_log("spp.php - Unexpected method " . $_SERVER["REQUEST_METHOD"]);
  die("Unexpected method");
}

if (isset($_GET["realm"])) {
  $realm = $_GET["realm"];
  $realm = PREG_REPLACE("/[^0-9a-zA-Z\.\-]/i", '', $realm);
} else {
  error_log("spp.php - Realm not specified");
  die("Realm not specified");
}

unset($user);
putenv("HS20CERT");

if (!empty($_SERVER['PHP_AUTH_DIGEST'])) {
  $needed = array('nonce'=>1, 'nc'=>1, 'cnonce'=>1, 'qop'=>1, 'username'=>1,
		  'uri'=>1, 'response'=>1);
  $data = array();
  $keys = implode('|', array_keys($needed));
  preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@',
		 $_SERVER['PHP_AUTH_DIGEST'], $matches, PREG_SET_ORDER);
  foreach ($matches as $m) {
    $data[$m[1]] = $m[3] ? $m[3] : $m[4];
    unset($needed[$m[1]]);
  }
  if ($needed) {
    error_log("spp.php - Authentication failed - missing: " . print_r($needed));
    die('Authentication failed');
  }
  $user = $data['username'];
  if (strlen($user) < 1) {
    error_log("spp.php - Authentication failed - empty username");
    die('Authentication failed');
  }


  $db = new PDO($osu_db);
  if (!$db) {
    error_log("spp.php - Could not access database");
    die("Could not access database");
  }
  $row = $db->query("SELECT password FROM users " .
		    "WHERE identity='$user' AND realm='$realm'")->fetch();
  if (!$row) {
    $row = $db->query("SELECT osu_password FROM users " .
		      "WHERE osu_user='$user' AND realm='$realm'")->fetch();
    $pw = $row['osu_password'];
  } else
    $pw = $row['password'];
  if (!$row) {
    error_log("spp.php - Authentication failed - user '$user' not found");
    die('Authentication failed');
  }
  if (strlen($pw) < 1) {
    error_log("spp.php - Authentication failed - empty password");
    die('Authentication failed');
  }

  $A1 = md5($user . ':' . $realm . ':' . $pw);
  $A2 = md5($_SERVER['REQUEST_METHOD'] . ':' . $data['uri']);
  $resp = md5($A1 . ':' . $data['nonce'] . ':' . $data['nc'] . ':' .
	      $data['cnonce'] . ':' . $data['qop'] . ':' . $A2);
  if ($data['response'] != $resp) {
    error_log("Authentication failure - response mismatch");
    die('Authentication failed');
  }
} else if (isset($_SERVER["SSL_CLIENT_VERIFY"]) &&
	   $_SERVER["SSL_CLIENT_VERIFY"] == "SUCCESS" &&
	   isset($_SERVER["SSL_CLIENT_M_SERIAL"])) {
  $user = "cert-" . $_SERVER["SSL_CLIENT_M_SERIAL"];
  putenv("HS20CERT=yes");
} else if (!isset($_SERVER["PATH_INFO"]) ||
	   $_SERVER["PATH_INFO"] != "/signup") {
  header('HTTP/1.1 401 Unauthorized');
  header('WWW-Authenticate: Digest realm="'.$realm.
	 '",qop="auth",nonce="'.uniqid().'",opaque="'.md5($realm).'"');
  error_log("spp.php - Authentication required (not signup)");
  die('Authentication required (not signup)');
}


if (isset($user) && strlen($user) > 0)
  putenv("HS20USER=$user");
else
  putenv("HS20USER");

putenv("HS20REALM=$realm");
putenv("HS20POST=$HTTP_RAW_POST_DATA");
$addr = $_SERVER["REMOTE_ADDR"];
putenv("HS20ADDR=$addr");

// Note that systemd + apache may run under chroot, and so your log file will
// be in some hard-to-find place like:
// /tmp/systemd-httpd.service-XqgPdBa/tmp/hs20_spp_server.log
$last = exec("$osu_root/spp/hs20_spp_server -r$osu_root -f/tmp/hs20_spp_server.log", $output, $ret);

if ($ret == 2) {
  if (empty($_SERVER['PHP_AUTH_DIGEST'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Digest realm="'.$realm.
           '",qop="auth",nonce="'.uniqid().'",opaque="'.md5($realm).'"');
    error_log("spp.php - Authentication required (ret 2)");
    die('Authentication required');
  } else {
    error_log("spp.php - Unexpected authentication error");
    die("Unexpected authentication error");
  }
}
if ($ret != 0) {
  error_log("spp.php - Failed to process SPP request");
  die("Failed to process SPP request");
}
//error_log("spp.php: Response: " . implode($output));

header("Content-Type: application/soap+xml");

echo implode($output);

?>
