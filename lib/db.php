<?php

// SQLite storage layer. Everything lives in one database file set by dbPath in config.php.
// Four tables: appdata holds the campaign setup blobs, reports holds dated report blobs,
// contacts is the active list, suppressed is the unsubscribe / pruned list.

// ---------------------------------------------------------------------------------

function GetConfig() {
static $config = null;
if ($config !== null) return $config;

$path = dirname(__DIR__) . "/config.php";
if (!file_exists($path)) {
	http_response_code(500);
	header("Content-Type: application/json");
	echo json_encode(array("error" => "config.php not found. Copy config.sample.php to config.php and fill in your settings."));
	exit;
}

$config = require $path;
if (!is_array($config)) $config = array();

$timezone = $config["timezone"] ?? "America/New_York";
if (strlen($timezone) > 0) @date_default_timezone_set($timezone);

return $config;
} // ends function

// ---------------------------------------------------------------------------------

function GetDB() {
static $db = null;
if ($db !== null) return $db;

$config = GetConfig();
$dbPath = $config["dbPath"] ?? (dirname(__DIR__) . "/data/emailcampaign.sqlite");

$dir = dirname($dbPath);
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$db = new PDO("sqlite:" . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode = WAL");
$db->exec("PRAGMA busy_timeout = 5000");

$db->exec("CREATE TABLE IF NOT EXISTS appdata (
	name TEXT PRIMARY KEY,
	content TEXT,
	updated INTEGER
)");

$db->exec("CREATE TABLE IF NOT EXISTS reports (
	name TEXT PRIMARY KEY,
	content TEXT,
	updated INTEGER
)");

$db->exec("CREATE TABLE IF NOT EXISTS contacts (
	id TEXT PRIMARY KEY,
	userdata TEXT,
	created INTEGER
)");

$db->exec("CREATE TABLE IF NOT EXISTS suppressed (
	id TEXT PRIMARY KEY,
	userdata TEXT,
	created INTEGER
)");

return $db;
} // ends function

// ---------------------------------------------------------------------------------
// The work queue folder holds the flat files built during scheduling
// (clicker and inactive record files per ISP, emails already used per
// sending domain, last run markers).

function GetQueueDir() {

$dir = dirname(__DIR__) . "/data/queue";
if (!is_dir($dir)) @mkdir($dir, 0775, true);
return $dir;
} // ends function

// ---------------------------------------------------------------------------------
// App data blobs. "app" is the campaign setup (settings, domains, messages,
// lists, series), "responders" is the per email autoresponder schedule map.

function GetAppData() {

$defaults = array("settings" => array(), "domains" => array(), "messages" => array(), "lists" => array(), "series" => array());

$db = GetDB();
$stmt = $db->prepare("SELECT content FROM appdata WHERE name = ?");
$stmt->execute(array("app"));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) return $defaults;

$appData = json_decode($row["content"], true);
if (!is_array($appData)) return $defaults;

foreach ($defaults as $key => $value) { if (!isset($appData[$key]) || !is_array($appData[$key])) $appData[$key] = $value; }
return $appData;
} // ends function

// ---------------------------------------------------------------------------------

function SaveAppData($appData) {

$db = GetDB();
$stmt = $db->prepare("INSERT INTO appdata (name, content, updated) VALUES (?, ?, ?)
	ON CONFLICT(name) DO UPDATE SET content = excluded.content, updated = excluded.updated");
$stmt->execute(array("app", json_encode($appData, JSON_INVALID_UTF8_SUBSTITUTE), time()));
return true;
} // ends function

// ---------------------------------------------------------------------------------

function GetResponders() {

$db = GetDB();
$stmt = $db->prepare("SELECT content FROM appdata WHERE name = ?");
$stmt->execute(array("responders"));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) return array("userdata" => array());

$responders = json_decode($row["content"], true);
if ((!is_array($responders)) || (!isset($responders["userdata"])) || (!is_array($responders["userdata"]))) return array("userdata" => array());
return $responders;
} // ends function

// ---------------------------------------------------------------------------------

function SaveResponders($userdata) {

$db = GetDB();
$stmt = $db->prepare("INSERT INTO appdata (name, content, updated) VALUES (?, ?, ?)
	ON CONFLICT(name) DO UPDATE SET content = excluded.content, updated = excluded.updated");
$stmt->execute(array("responders", json_encode(array("userdata" => $userdata), JSON_INVALID_UTF8_SUBSTITUTE), time()));
return true;
} // ends function

// ---------------------------------------------------------------------------------
// Reports. Names are date-stamped, for example daily07-08-2026, broadcasts07-08-2026,
// responders07-08-2026, datacount07-08-2026.

function GetReport($name) {

$db = GetDB();
$stmt = $db->prepare("SELECT content FROM reports WHERE name = ?");
$stmt->execute(array($name));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) return false;

$content = json_decode($row["content"], true);
if (!is_array($content)) return false;
return $content;
} // ends function

// ---------------------------------------------------------------------------------

function SaveReport($name, $content) {

$db = GetDB();
$stmt = $db->prepare("INSERT INTO reports (name, content, updated) VALUES (?, ?, ?)
	ON CONFLICT(name) DO UPDATE SET content = excluded.content, updated = excluded.updated");
$stmt->execute(array($name, json_encode($content, JSON_INVALID_UTF8_SUBSTITUTE), time()));
return true;
} // ends function

// ---------------------------------------------------------------------------------
// Contacts. The id is the lowercased email address, userdata is the profile
// blob (ISP, fname, feed, activity, sendCount, message_history and so on).

function GetContact($id) {

$db = GetDB();
$stmt = $db->prepare("SELECT userdata FROM contacts WHERE id = ?");
$stmt->execute(array($id));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) return false;

$userdata = json_decode($row["userdata"], true);
if (!is_array($userdata)) return false;
return $userdata;
} // ends function

// ---------------------------------------------------------------------------------

function SaveContact($id, $userdata) {

$db = GetDB();
$stmt = $db->prepare("INSERT INTO contacts (id, userdata, created) VALUES (?, ?, ?)
	ON CONFLICT(id) DO UPDATE SET userdata = excluded.userdata");
$stmt->execute(array($id, json_encode($userdata, JSON_INVALID_UTF8_SUBSTITUTE), time()));
return true;
} // ends function

// ---------------------------------------------------------------------------------

function DeleteContact($id) {

$db = GetDB();
$stmt = $db->prepare("DELETE FROM contacts WHERE id = ?");
$stmt->execute(array($id));
return true;
} // ends function

// ---------------------------------------------------------------------------------

function ContactsLoop() {

$db = GetDB();
$stmt = $db->query("SELECT id, userdata FROM contacts ORDER BY id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$userdata = json_decode($row["userdata"], true);
	if (!is_array($userdata)) $userdata = array();
	yield array("id" => $row["id"], "userdata" => $userdata);
}
} // ends function

// ---------------------------------------------------------------------------------

function CountContacts() {

$db = GetDB();
return (int)$db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
} // ends function

// ---------------------------------------------------------------------------------
// Suppressed records. Same id scheme as contacts. A record lands here when it
// unsubscribes, hard bounces, complains, or gets pruned, and stays here so
// re-imports can be blocked or restored knowingly.

function GetSuppressed($id) {

$db = GetDB();
$stmt = $db->prepare("SELECT userdata FROM suppressed WHERE id = ?");
$stmt->execute(array($id));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) return false;

$userdata = json_decode($row["userdata"], true);
if (!is_array($userdata)) $userdata = array();
return $userdata;
} // ends function

// ---------------------------------------------------------------------------------

function SaveSuppressed($id, $userdata) {

$db = GetDB();
$stmt = $db->prepare("INSERT INTO suppressed (id, userdata, created) VALUES (?, ?, ?)
	ON CONFLICT(id) DO UPDATE SET userdata = excluded.userdata");
$stmt->execute(array($id, json_encode($userdata, JSON_INVALID_UTF8_SUBSTITUTE), time()));
return true;
} // ends function

// ---------------------------------------------------------------------------------

function DeleteSuppressed($id) {

$db = GetDB();
$stmt = $db->prepare("DELETE FROM suppressed WHERE id = ?");
$stmt->execute(array($id));
return true;
} // ends function

// ---------------------------------------------------------------------------------

function CountSuppressed() {

$db = GetDB();
return (int)$db->query("SELECT COUNT(*) FROM suppressed")->fetchColumn();
} // ends function

?>
