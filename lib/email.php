<?php

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/smtp.php";

// Core email campaign engine: broadcasts, autoresponder series, contact
// profiles, suppression, delivery webhooks, and click tracking.
// Webhook parsers cover: SendGrid, Mailgun, Postmark, Amazon SES, SMTP2GO,
// Brevo, SparkPost, Elastic Email, Mailtrap, Mailjet, SMTP.com.
// Sending goes through lib/smtp.php, which works with all of them.

// ---------------------------------------------------------------------------------
// Shared small helpers

function CleanEmail($email) {

$email = strtolower(trim((string)$email));
if ((strlen($email) < 5) || (strpos($email, "@") === false)) return "";
return $email;
} // ends function

// ---------------------------------------------------------------------------------

function GetDailyReports($date = "") {

if (strlen($date) < 1) $date = date("m-d-Y");
$report = GetReport("daily" . $date);
if ((!$report) || (!isset($report["reports"]))) $report = array("reports" => array());
foreach (array("delivered", "bounced", "opens", "clicks", "unsubs", "complaints", "hard") as $key) { if (!isset($report["reports"][$key])) $report["reports"][$key] = 0; }
return $report;
} // ends function

// ---------------------------------------------------------------------------------

function SaveDailyReports($report, $date = "") {

if (strlen($date) < 1) $date = date("m-d-Y");
SaveReport("daily" . $date, $report);
return true;
} // ends function

// ---------------------------------------------------------------------------------
// Splits an uploaded data file into usable lines. Handles Windows line ends
// and skips a header row when the first field looks like a column name.

function ConvertDataFile($content) {

$content = str_replace("\r\n", "\n", (string)$content);
$content = str_replace("\r", "\n", $content);
$dataLines = explode("\n", $content);

if (count($dataLines) > 0) {
	$firstField = strtolower(trim(explode(",", $dataLines[0])[0] ?? ""));
	if (in_array($firstField, array("email", "phone", "email_address", "phone_number"))) array_shift($dataLines);
}

return $dataLines;
} // ends function

// ---------------------------------------------------------------------------------
// Domain and provider lookup from the domains list in the admin area.

function GetDomainEntry($domain, $appData = false) {

if (!$appData) $appData = GetAppData();
if (empty($appData["domains"])) return false;

foreach ($appData["domains"] as $index => $domainEntry)
{
	if (($domainEntry["domain"] ?? "") == $domain) return $domainEntry;
}

return false;
} // ends function

// ---------------------------------------------------------------------------------

function GetEmailProviderInfo($domain, $appData = false) {

$domainEntry = GetDomainEntry($domain, $appData);
if (!$domainEntry) return false;

$alias = $domainEntry["alias"] ?? "";
$senderName = $domainEntry["senderName"] ?? "";
$smtpHost = $domainEntry["smtpHost"] ?? "";
$smtpUser = $domainEntry["smtpUser"] ?? "";
$smtpPass = $domainEntry["smtpPass"] ?? "";
$smtpPort = $domainEntry["smtpPort"] ?? 587;
$provider = $domainEntry["provider"] ?? "";

if (strlen($smtpHost) < 3) return false;
return array("domain" => $domain, "alias" => $alias, "senderName" => $senderName, "sender" => $alias . "@" . $domain, "smtpHost" => $smtpHost, "smtpUser" => $smtpUser, "smtpPass" => $smtpPass, "smtpPort" => $smtpPort, "provider" => $provider);
} // ends function

// ---------------------------------------------------------------------------------

function BuildUnsubUrl($email, $series = "") {

$config = GetConfig();
$url = rtrim($config["publicUrl"] ?? "", "/") . "/unsub.php?e=" . urlencode($email);
if (strlen($series) > 0) $url .= "&s=" . urlencode($series);
return $url;
} // ends function

// ---------------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------------

function SendEmailMessageAPI($userdata) {

$email = CleanEmail($userdata["email"] ?? "");
$subject = $userdata["subject"] ?? "";
$html = $userdata["html"] ?? $userdata["message"] ?? "";
$text = $userdata["text"] ?? strip_tags($html);
$smtpHost = $userdata["smtpHost"] ?? "";
$sender = $userdata["sender"] ?? "";
$unsubUrl = $userdata["unsubUrl"] ?? "";

if (strlen($email) < 5) return array("error" => "invalid email address.");
if (strlen($smtpHost) < 3) return array("error" => "invalid smtp host.");

$headers = array();
if (strlen($unsubUrl) > 5) {
	$headers[] = "List-Unsubscribe: <mailto:" . $sender . ">,<" . $unsubUrl . ">";
	$headers[] = "List-Unsubscribe-Post: List-Unsubscribe=One-Click";
}

$params = array(
	"smtpHost" => $smtpHost,
	"smtpUser" => $userdata["smtpUser"] ?? "",
	"smtpPass" => $userdata["smtpPass"] ?? "",
	"smtpPort" => $userdata["smtpPort"] ?? 587,
	"sender" => $sender,
	"senderName" => $userdata["senderName"] ?? "",
	"email" => $email,
	"subject" => $subject,
	"html" => $html,
	"text" => $text,
	"headers" => $headers,
);

$error = SMTPSendEmail($params);

return array("error" => $error, "message" => (strlen($error) < 1) ? "Message Sent" : "");
} // ends function

// ---------------------------------------------------------------------------------

function SendEmailMessage($jsonData = array(), $appData = false) {

$providerInfo = GetEmailProviderInfo($jsonData["domain"] ?? "", $appData);
if (!$providerInfo) return array("error" => "Provider info is invalid, add the domain in the admin area first.");

$jsonData["sender"] = $providerInfo["sender"];
$jsonData["senderName"] = $providerInfo["senderName"];
$jsonData["smtpHost"] = $providerInfo["smtpHost"];
$jsonData["smtpUser"] = $providerInfo["smtpUser"];
$jsonData["smtpPass"] = $providerInfo["smtpPass"];
$jsonData["smtpPort"] = $providerInfo["smtpPort"];

return SendEmailMessageAPI($jsonData);
} // ends function

// ---------------------------------------------------------------------------------
// Autoresponders
// ---------------------------------------------------------------------------------

// Sends every responder message that is due right now. Safe to run as often
// as you like from cron, each schedule entry is marked sent once.
function SendEmailResponderMessages() {

$total = 0;
$changed = 0;
$currentTime = time();
$dayOfWeek = date("w");
$hourOfDay = (int)date("G");

$appData = GetAppData();
$responders = GetResponders();
if (empty($responders["userdata"])) return 0;

foreach ($responders["userdata"] as $email => $emailSeries) {
foreach ($emailSeries as $series => $schedulearray)
{
	$currentSeriesIndex = -1;
	foreach ($schedulearray as $seriesIndex => $schedule) { if (strlen($schedule["status"] ?? "") < 1) { $currentSeriesIndex = $seriesIndex; break; }  }
	if ($currentSeriesIndex < 0) continue;

	$domain = $schedulearray[$currentSeriesIndex]["domain"] ?? "";
	if (strlen($domain) < 3) continue;

	if (!empty($schedulearray[$currentSeriesIndex]["exceptDays"])) if (in_array($dayOfWeek, $schedulearray[$currentSeriesIndex]["exceptDays"])) continue;
	if (!empty($schedulearray[$currentSeriesIndex]["exceptHours"])) if (in_array($hourOfDay, $schedulearray[$currentSeriesIndex]["exceptHours"])) continue;
	if ($currentTime < ($schedulearray[$currentSeriesIndex]["date"] ?? 0)) continue;

	$total++;
	$schedulearray[$currentSeriesIndex]["email"] = $email;
	$schedulearray[$currentSeriesIndex]["series"] = $series;
	$schedulearray[$currentSeriesIndex]["seriesIndex"] = $currentSeriesIndex;

	SendEmailResponderMessage($schedulearray[$currentSeriesIndex], $appData);

	$responders["userdata"][$email][$series][$currentSeriesIndex]["status"] = "sent";
	$changed++;
}
}

if ($changed > 0) SaveResponders($responders["userdata"]);

return $total;
} // ends function

// ---------------------------------------------------------------------------------

function SendEmailResponderMessage($messageData = array(), $appData = array()) {

$email = CleanEmail($messageData["email"] ?? "");
if (strlen($email) < 5) return array("error" => "email is invalid.");
$domain = $messageData["domain"] ?? "";
if (strlen($domain) < 3) return array("error" => "domain is invalid.");
$series = (string)($messageData["series"] ?? "");
if (strlen($series) < 1) return array("error" => "series is invalid.");
$messageID = (string)($messageData["messageID"] ?? "");
if (strlen($messageID) < 1) return array("error" => "messageID is invalid.");
$seriesIndex = (string)($messageData["seriesIndex"] ?? "");
if (strlen($seriesIndex) < 1) return array("error" => "seriesIndex is invalid.");

if (empty($appData)) $appData = GetAppData();
if (!isset($appData["messages"][$messageID])) return array("error" => "messageID not found.");

$providerInfo = GetEmailProviderInfo($domain, $appData);
if (!$providerInfo) return array("error" => "Provider info is invalid.");

$fname = $messageData["fname"] ?? "";
$subid = $messageID . "/" . $series . "-" . $seriesIndex;

$thisMsg = $appData["messages"][$messageID];
$html = stripcslashes($thisMsg["html"] ?? "");
$text = $thisMsg["text"] ?? "";
$subject = $thisMsg["subject"] ?? "";

if (strlen($fname) > 9)
{
	if (substr_count($fname, " ") > 0)
	{
		$namearray = explode(" ", $fname);
		$fname = $namearray[0];
	}
	if (strlen($fname) > 9) $fname = "";
}

$unsubUrl = BuildUnsubUrl($email, $series);

$html = str_replace ("##SUBID##", $subid, $html);
$html = str_replace ("##FNAME##", $fname, $html);
$html = str_replace ("##DOMAIN##", $providerInfo["domain"], $html);
$html = str_replace ("##UNSUB##", $unsubUrl, $html);
$text = str_replace ("##SUBID##", $subid, $text);
$text = str_replace ("##FNAME##", $fname, $text);
$text = str_replace ("##UNSUB##", $unsubUrl, $text);
$subject = str_replace ("##FNAME##", $fname, $subject);

$sendMessage = array("email" => $email, "subject" => $subject, "html" => $html, "text" => $text, "smtpHost" => $providerInfo["smtpHost"], "smtpUser" => $providerInfo["smtpUser"], "smtpPass" => $providerInfo["smtpPass"], "smtpPort" => $providerInfo["smtpPort"], "sender" => $providerInfo["sender"], "senderName" => $providerInfo["senderName"], "unsubUrl" => $unsubUrl);

return SendEmailMessageAPI($sendMessage);
} // ends function

// ---------------------------------------------------------------------------------

function CreateEmailResponderSeries($messageData = array()) {

/*
required:
email - valid email address
series - id for message list

optional:
starttime - timestamp for first message
fname - first name
ISP - gmail,outlook,yahoo,aol,cables,other
excludeISPs - comma list to skip
feed - for tracking
*/

$email = CleanEmail($messageData["email"] ?? "");
if (strlen($email) < 5) return array("error" => "email address is invalid.");

$series = (string)($messageData["series"] ?? "");
if (strlen($series) < 1) return array("error" => "series is required.");

$starttime = $messageData["starttime"] ?? time();
$feed = $messageData["feed"] ?? "";
$fname = $messageData["fname"] ?? "";
$ISP = $messageData["ISP"] ?? TranslateISPToGroup($email);

if ((strlen($messageData["excludeISPs"] ?? "") > 0) && (strlen($ISP) > 0))
{
	$exclude = explode(",", $messageData["excludeISPs"]);
	if (in_array($ISP, $exclude)) return array("message" => "Email excluded by ISP list");
}

$appData = GetAppData();
if (empty($appData["series"][$series])) return array("error" => "Series not found. Create it in the admin area first.");
$seriesData = $appData["series"][$series];

$localHour = (int)date("G");

$sendNow = "0";
if (($localHour >= 8) && ($localHour <= 17)) $sendNow = "1";
if ($starttime > (time() + 36000)) $sendNow = "0";

$domain = $seriesData["domain"] ?? "";
if (strlen($domain) < 3) return array("error" => "domain is invalid.");

$providerInfo = GetEmailProviderInfo($domain, $appData);
if (!$providerInfo) return array("error" => "Provider info is invalid.");

$freq = $seriesData["freq"] ?? "";
$exceptHours = $seriesData["exceptHours"] ?? array();
$exceptDays = $seriesData["exceptDays"] ?? array();
$messageList = $seriesData["messageList"] ?? array();

if ((strlen($freq) < 1) || (count($messageList) < 1)) return array("error" => "Series is not configured correctly.");
$schedule = array();

$numdays = 1;
switch ($freq)
{
	case "daily":
		$numdays = 1;
	break;
	case "weekly":
		$numdays = 7;
	break;
	case "monthly":
		$numdays = 30;
	break;
}

$currentTime = $starttime;
foreach ($messageList as $index => $messageID)
{
	$schedule[] = array("date" => $currentTime, "messageID" => $messageID, "exceptHours" => $exceptHours, "feed" => $feed, "fname" => $fname, "domain" => $domain, "exceptDays" => $exceptDays, "ISP" => $ISP, "series" => $series, "status" => "");
	$currentTime += 86400 * $numdays;
}

$responders = GetResponders();
if (!empty($responders["userdata"][$email][$series])) return array("error" => "Failed, " . $email . " is already subscribed to responder series: " . $series);

if (!is_array($responders["userdata"])) $responders["userdata"] = array();
if (!isset($responders["userdata"][$email]) || !is_array($responders["userdata"][$email])) $responders["userdata"][$email] = array();
$responders["userdata"][$email][$series] = $schedule;

if ($sendNow == "1") {
$messageData["messageID"] = $messageList[0];
$messageData["series"] = $series;
$messageData["seriesIndex"] = 0;
$messageData["email"] = $email;
$messageData["domain"] = $domain;
SendEmailResponderMessage($messageData, $appData);

$responders["userdata"][$email][$series][0]["status"] = "sent";
SaveResponders($responders["userdata"]);

$messageID = $messageList[0];
$redirectUrl = $appData["messages"][$messageID]["redirectUrl"] ?? "";
$appReports = GetReport("responders" . date("m-d-Y"));
if (!$appReports) $appReports = array("userdata" => array());
if (!isset($appReports["userdata"][$series])) $appReports["userdata"][$series] = array();
$appReports["userdata"][$series][$email] = array("seriesIndex" => 0, "redirectUrl" => $redirectUrl, "subid" => $messageID . "/" . $series . "-0", "time" => time(), "messageID" => $messageID, "domain" => $domain);
SaveReport("responders" . date("m-d-Y"), $appReports);

return array("message" => "Series: " . $series . " created. Message 0 sent to: " . $email);  }

SaveResponders($responders["userdata"]);

return array("message" => "Responder series: " . $series . " created for: " . $email);
} // ends function

// ---------------------------------------------------------------------------------

function RecordEmailResponderUnsub($jsonData) {

$email = CleanEmail($jsonData["email"] ?? "");
$series = (string)($jsonData["series"] ?? "");
if (strlen($email) < 5) return false;

if (!GetSuppressed($email)) SaveSuppressed($email, array("unsubDate" => time()));

$responders = GetResponders();
if (empty($responders["userdata"][$email])) return true;

if (strlen($series) > 0) { if (empty($responders["userdata"][$email][$series])) return true; unset($responders["userdata"][$email][$series]); }
else unset($responders["userdata"][$email]);

SaveResponders($responders["userdata"]);

if (strlen($series) > 0) {
$appReports = GetReport("responders" . date("m-d-Y"));
if (!$appReports) $appReports = array("userdata" => array());
if (!isset($appReports["userdata"][$series])) $appReports["userdata"][$series] = array();
$appReports["userdata"][$series][$email] = array("activity" => "u", "time" => time());
SaveReport("responders" . date("m-d-Y"), $appReports);  }

return true;
} // ends function

// ---------------------------------------------------------------------------------

function EmailUnsubFromAll($jsonData) {

$email = CleanEmail($jsonData["email"] ?? "");
if (strlen($email) < 5) return false;
RecordEmailResponderUnsub(array("email" => $email));
RecordEmailBroadcastUnsub(array("email" => $email, "domain" => $jsonData["domain"] ?? ""));

return true;
} // ends function

// ---------------------------------------------------------------------------------

function UploadEmailUnsubs($jsonData) {

$total = 0;
$content = $jsonData["content"] ?? "";

$dataLines = explode("\n", str_replace("\r", "", $content));
foreach ($dataLines as $index => $email)
{
	$email = CleanEmail($email);
	if (strlen($email) < 5) continue;

	RecordEmailResponderUnsub(array("email" => $email));
	RecordEmailBroadcastUnsub(array("email" => $email));
	$total++;
}

return array("message" => "Unsubscribed " . $total . " address(es).");
} // ends function

// ---------------------------------------------------------------------------------
// Contact upload format, one record per line:
// email,fname,lname,feed,sourceurl,optin,ip,state,country

function UploadEmailData($jsonData) {

$total = 0;
$restored = 0;
$skipped = 0;
$content = $jsonData["content"] ?? "";

$dataLines = ConvertDataFile($content);
foreach ($dataLines as $index => $line) {
$line = trim($line);
$fields = explode(",", $line);

$email = CleanEmail($fields[0] ?? "");
if (strlen($email) < 5) continue;

$suppressed = GetSuppressed($email);
if (!$suppressed) {
if (GetContact($email)) { $skipped++; continue; }
$userdata = array();
$userdata["ISP"] = TranslateISPToGroup($email);
$userdata["sourceurl"] = trim($fields[4] ?? "");
$userdata["optin"] = trim($fields[5] ?? "");
$userdata["fname"] = ucwords(strtolower(trim($fields[1] ?? "")));
$userdata["lname"] = ucwords(strtolower(trim($fields[2] ?? "")));
$userdata["feed"] = trim($fields[3] ?? "default");
$userdata["state"] = trim($fields[7] ?? "");
$userdata["country"] = trim($fields[8] ?? "US");
$userdata["ip"] = trim($fields[6] ?? "");
$userdata["created"] = time();

SaveContact($email, $userdata);
$total++;  }

else if (($suppressed["pruned"] ?? 0) == 1) {
unset($suppressed["pruned"]);
unset($suppressed["removeDate"]);
$suppressed["reImportDate"] = time();
DeleteSuppressed($email);
SaveContact($email, $suppressed);
$restored++;  }

else $skipped++;  }

return array("message" => "Imported " . $total . " new, restored " . $restored . " pruned, skipped " . $skipped . " (unsubscribed or already present).");
} // ends function

// ---------------------------------------------------------------------------------

function UploadEmailResponders($jsonData) {

$total = 0;
$content = $jsonData["content"] ?? "";
$series = (string)($jsonData["series"] ?? "");

$dataLines = ConvertDataFile($content);
foreach ($dataLines as $index => $line) {
$line = trim($line);
$fields = explode(",", $line);

$email = CleanEmail($fields[0] ?? "");
if (strlen($email) < 5) continue;

$userdata = array();
$userdata["email"] = $email;
$userdata["series"] = $series;
$userdata["feed"] = trim($fields[3] ?? "");
$userdata["fname"] = trim($fields[1] ?? "");
$userdata["ISP"] = TranslateISPToGroup($email);

$result = CreateEmailResponderSeries($userdata);
if (strlen($result["error"] ?? "") < 1) $total++;  }

return array("message" => "Subscribed " . $total . " address(es) to series " . $series . ".");
} // ends function

// ---------------------------------------------------------------------------------
// Broadcasts
// ---------------------------------------------------------------------------------

// Sends the current hour's bucket for one scheduled broadcast. Each hour is
// only sent once, so cron can call this as often as it wants.
function SendEmailBroadcastMessages($jsonData) {

$bid = (string)($jsonData["bid"] ?? "");
if (strlen($bid) < 1) return false;

$total = 0;
$reportName = "broadcasts" . date("m-d-Y");

$bidData = GetReport($reportName);
if ((!$bidData) || (empty($bidData["userdata"][$bid]))) return false;
$broadcastQueueJson = $bidData["userdata"][$bid];

$currentHour = date("H");
if (in_array($currentHour, $broadcastQueueJson["hoursSent"] ?? array())) return 0;

$domain = $broadcastQueueJson["domain"] ?? "";
$smtpHost = $broadcastQueueJson["smtpHost"] ?? "";
$smtpUser = $broadcastQueueJson["smtpUser"] ?? "";
$smtpPass = $broadcastQueueJson["smtpPass"] ?? "";
$smtpPort = $broadcastQueueJson["smtpPort"] ?? 587;
$sender = $broadcastQueueJson["sender"] ?? "";
$senderName = $broadcastQueueJson["senderName"] ?? "";

if (strlen($domain) < 3) return false;
if (strlen($smtpHost) < 3) return false;

$html = stripcslashes($broadcastQueueJson["html"] ?? "");
$text = $broadcastQueueJson["text"] ?? "";
$subject = $broadcastQueueJson["subject"] ?? "";

for ($i = 0; $i < count($broadcastQueueJson["messages"] ?? array()); $i++)
{
	if ($currentHour != sprintf("%02d", (int)($broadcastQueueJson["messages"][$i]["starthour"] ?? -1))) continue;
	$total++;

	$email = $broadcastQueueJson["messages"][$i]["email"];
	$subid = stripcslashes($broadcastQueueJson["messages"][$i]["subid"] ?? "");
	$fname = $broadcastQueueJson["messages"][$i]["fname"] ?? "";

	if (strlen($fname) > 9)
	{
		if (substr_count($fname, " ") > 0)
		{
			$namearray = explode(" ", $fname);
			$fname = $namearray[0];
		}
		if (strlen($fname) > 9) $fname = "";
	}

	$unsubUrl = BuildUnsubUrl($email);

	$tempHtml = str_replace ("##SUBID##", $subid, $html);
	$tempHtml = str_replace ("##FNAME##", $fname, $tempHtml);
	$tempHtml = str_replace ("##DOMAIN##", $domain, $tempHtml);
	$tempHtml = str_replace ("##UNSUB##", $unsubUrl, $tempHtml);
	$tempText = str_replace ("##SUBID##", $subid, $text);
	$tempText = str_replace ("##FNAME##", $fname, $tempText);
	$tempText = str_replace ("##UNSUB##", $unsubUrl, $tempText);
	$tempSubject = str_replace ("##FNAME##", $fname, $subject);

	$messageData = array("email" => $email, "subject" => $tempSubject, "html" => $tempHtml, "text" => $tempText, "smtpHost" => $smtpHost, "smtpUser" => $smtpUser, "smtpPass" => $smtpPass, "smtpPort" => $smtpPort, "sender" => $sender, "senderName" => $senderName, "unsubUrl" => $unsubUrl);
	SendEmailMessageAPI($messageData);
}

$bidData = GetReport($reportName);
if (($bidData) && (isset($bidData["userdata"][$bid]))) {
if (!isset($bidData["userdata"][$bid]["hoursSent"])) $bidData["userdata"][$bid]["hoursSent"] = array();
$bidData["userdata"][$bid]["hoursSent"][] = $currentHour;
SaveReport($reportName, $bidData);  }

return $total;
} // ends function

// ---------------------------------------------------------------------------------

// Walks the whole contact list, prunes dead records, refreshes counts by
// ISP and feed, and rebuilds the clicker and inactive queue files that
// broadcast scheduling reads from.
function BroadcastDataQuery($jsonData = array()) {

$appData = GetAppData();
$baseEmailDir = GetQueueDir();

$allISPs = array("gmail", "outlook", "yahoo", "cables", "aol", "other");
$totalConverters = 0;
$totalClickers = 0;
$totalInactive = 0;

$trackingFeed = array();
$trackingISP = array();

foreach ($allISPs as $thisISP)
{
	@unlink($baseEmailDir . "/" . $thisISP . "_clickerRecords.txt");
	@unlink($baseEmailDir . "/" . $thisISP . "_inactiveRecords.txt");
}

if (!isset($appData["settings"]["pruningCount"])) $appData["settings"]["pruningCount"] = 0;
if (!isset($appData["settings"]["daysSinceClick"])) $appData["settings"]["daysSinceClick"] = 30;
$timeAllowedSinceClick = 86400 * $appData["settings"]["daysSinceClick"];

$currentTime = time();
foreach (ContactsLoop() as $response)
{
	$email = $response['id'];
	$userdata = $response['userdata'];
	$userdata["email"] = $email;
	if (strlen($userdata["ISP"] ?? "") < 1) $userdata["ISP"] = "other";
	$userdata["activity"] = $userdata["activity"] ?? "";
	$userdata["sendCount"] = $userdata["sendCount"] ?? 0;

	// pruning: no activity after N messages, and clickers with no click in the allowed window
	if ($appData["settings"]["pruningCount"] > 0) {
	if ((($userdata["sendCount"] >= $appData["settings"]["pruningCount"]) && ($userdata["activity"] == "")) || (($userdata["activity"] == "c") && (($userdata["lastClick"] ?? 0) < ($currentTime - $timeAllowedSinceClick))))
	{
		$userdata["pruned"] = 1;
		$userdata["removeDate"] = $currentTime;
		unset($userdata["email"]);

		SaveSuppressed($email, $userdata);
		DeleteContact($email);
		continue;
	}  }

	if (($userdata["activity"] == "a") && (($userdata["lastConvert"] ?? 0) < $currentTime - (86400 * 30))) $userdata["activity"] = "c"; // demote old converters

	if (strlen($userdata["feed"] ?? "") > 0)
	{
		$tempFeeds = explode(",", $userdata["feed"]);
		foreach ($tempFeeds as $tFeed) {
		if (!isset($trackingFeed[$tFeed])) $trackingFeed[$tFeed] = array("totalConverters" => 0, "totalClickers" => 0, "totalInactive" => 0);
		if ($userdata["activity"] == "a") $trackingFeed[$tFeed]["totalConverters"]++;
		else if ($userdata["activity"] == "c") $trackingFeed[$tFeed]["totalClickers"]++;
		else $trackingFeed[$tFeed]["totalInactive"]++;  }
	}

	if (!isset($trackingISP[$userdata["ISP"]])) $trackingISP[$userdata["ISP"]] = array("totalConverters" => 0, "totalClickers" => 0, "totalInactive" => 0);
	if ($userdata["activity"] == "a") $trackingISP[$userdata["ISP"]]["totalConverters"]++;
	else if ($userdata["activity"] == "c") $trackingISP[$userdata["ISP"]]["totalClickers"]++;
	else $trackingISP[$userdata["ISP"]]["totalInactive"]++;

	if ($userdata["activity"] == "a") $totalConverters++;
	else if ($userdata["activity"] == "c") $totalClickers++;
	else $totalInactive++;

	if (($userdata["activity"] == "a") || ($userdata["activity"] == "c")) @file_put_contents($baseEmailDir . "/" . $userdata["ISP"] . "_clickerRecords.txt", json_encode($userdata) . "\n", FILE_APPEND);
	else @file_put_contents($baseEmailDir . "/" . $userdata["ISP"] . "_inactiveRecords.txt", json_encode($userdata) . "\n", FILE_APPEND);
}

$dataCounts = array();
$dataCounts["lastQueried"] = date("m-d-Y");
$dataCounts["feeds"] = $trackingFeed;
$dataCounts["totalClickers"] = $totalClickers;
$dataCounts["totalConverters"] = $totalConverters;
$dataCounts["totalInactive"] = $totalInactive;
foreach ($allISPs as $thisISP) $dataCounts[$thisISP] = $trackingISP[$thisISP] ?? array("totalConverters" => 0, "totalClickers" => 0, "totalInactive" => 0);

SaveReport("datacount" . date("m-d-Y"), $dataCounts);

return $dataCounts;
} // ends function

// ---------------------------------------------------------------------------------

function GetHighestBroadcastID() {

$highest = 0;
$queryDate = $starttime = time();

while ($queryDate > ($starttime - (30 * 86400))) {
$broadcastData = GetReport("broadcasts" . date("m-d-Y", $queryDate));
if ((!$broadcastData) || (empty($broadcastData["userdata"]))) { $queryDate -= 86400; continue; }
foreach ($broadcastData["userdata"] as $bidcontent) {
if (($bidcontent["bid"] ?? 0) > $highest) $highest = $bidcontent["bid"];  }
if ($highest > 0) break;
$queryDate -= 86400;  }

return $highest;
} // ends function

// ---------------------------------------------------------------------------------

// The injector. Builds today's broadcasts automatically from every list with
// a volume set, rotating through the messages that are enabled for today.
function AutoScheduleEmail($jsonData = array()) {

if (empty($jsonData["doDatabaseQuery"])) $jsonData["doDatabaseQuery"] = "1";
$baseEmailDir = GetQueueDir();

$dayOfWeek = date("w");
$broadcastQueueJson = array();
$scheduled = 0;

$allOffers = array();
$sendCountArray = array();
$emailLists = array();
$allBroadcasts = array();
$nonFeedBroadcasts = array();
$listIndex = -1;

$appData = GetAppData();
$broadcastID = GetHighestBroadcastID();
if ($broadcastID < 1) $broadcastID = 1;

foreach ($appData["messages"] as $offerid => $emailOffer)
{
	if (strlen($emailOffer["domain"] ?? "") < 3) continue;

	$autoSchedule = explode(",", $emailOffer["arstatus"] ?? "");
	if (($autoSchedule[$dayOfWeek] ?? "0") == "1")
	{
		$emailOffer["offerid"] = $offerid;
		if (!isset($allOffers[$emailOffer["domain"]])) $allOffers[$emailOffer["domain"]] = array();
		$allOffers[$emailOffer["domain"]][] = $emailOffer;
	}
}
if (count($allOffers) < 1) return array("message" => "No messages are enabled for auto scheduling today.");

$allDomains = array();
foreach ($appData["lists"] as $listID => $listConfig)
{
	$domain = $listConfig["domain"] ?? "";
	if (strlen($domain) < 3) continue;
	if (empty($allOffers[$domain])) continue;
	if (!in_array($domain, $allDomains)) $allDomains[] = $domain;

	$providerInfo = GetEmailProviderInfo($domain, $appData);
	if (!$providerInfo) continue;

	$totalToSend = (int)($listConfig["totalVolume"] ?? 0);
	if ($totalToSend < 1) continue;

	if ($totalToSend >= 100000) $totalToSend = 99999;
	$listConfig["totalToSend"] = $totalToSend;

	$listIndex++;
	$sendCountArray[$listIndex] = 0;
	$emailLists[$listIndex] = $listConfig;

	$broadcastQueueJson[$listIndex] = array();
	$broadcastQueueJson[$listIndex]["messages"] = array();
	$broadcastQueueJson[$listIndex]["broadcast_date"] = date("m-d-Y");
	$broadcastQueueJson[$listIndex]["domain"] = $domain;
	$broadcastQueueJson[$listIndex]["listName"] = $emailLists[$listIndex]["name"] ?? "";
	$broadcastQueueJson[$listIndex]["smtpHost"] = $providerInfo["smtpHost"];
	$broadcastQueueJson[$listIndex]["smtpUser"] = $providerInfo["smtpUser"];
	$broadcastQueueJson[$listIndex]["smtpPass"] = $providerInfo["smtpPass"];
	$broadcastQueueJson[$listIndex]["smtpPort"] = $providerInfo["smtpPort"];
	$broadcastQueueJson[$listIndex]["sender"] = $providerInfo["sender"];
	$broadcastQueueJson[$listIndex]["senderName"] = $providerInfo["senderName"];
	$broadcastQueueJson[$listIndex]["provider"] = $providerInfo["provider"];
	$broadcastQueueJson[$listIndex]["totalToSend"] = $totalToSend;

	// broadcasts with feeds go first to get the right data, then non feed broadcasts take the rest
	if (strlen($emailLists[$listIndex]["allowedFeeds"] ?? "") > 0)
	{
		$allBroadcasts[] = $listIndex;
		$broadcastQueueJson[$listIndex]["allowedFeeds"] = explode(",", $emailLists[$listIndex]["allowedFeeds"]);
	}
	else $nonFeedBroadcasts[] = $listIndex;
}

foreach ($nonFeedBroadcasts as $listIndex) $allBroadcasts[] = $listIndex;
if (count($allBroadcasts) < 1) return array("message" => "No lists are ready to schedule (check domains, SMTP settings, and volumes).");

if ($jsonData["doDatabaseQuery"] != "0") {
foreach ($allDomains as $domain) @unlink($baseEmailDir . "/allEmailsUsed_" . $domain . ".txt");
BroadcastDataQuery();  }

$reportName = "broadcasts" . date("m-d-Y");
$response = GetReport($reportName);
$todaysbroadcasts = ($response && isset($response["userdata"])) ? $response["userdata"] : array();
$offer_rotate = array();

foreach ($allBroadcasts as $listIndex) {

$broadcastID++;
$totalToSend = $emailLists[$listIndex]["totalToSend"];

$domain = $emailLists[$listIndex]["domain"];
if (!isset($offer_rotate[$domain])) $offer_rotate[$domain] = 0;

// allOffers holds only the messages turned on for today, rotated per domain
$thisOffer = $allOffers[$domain][$offer_rotate[$domain]];
$offerid = $thisOffer["offerid"];
$html = $thisOffer["html"] ?? "";
$text = $thisOffer["text"] ?? "";
$subject = $thisOffer["subject"] ?? "";
$redirectUrl = $thisOffer["redirectUrl"] ?? "";
$offername = $thisOffer["name"] ?? "";

$offer_rotate[$domain]++;
if ($offer_rotate[$domain] >= count($allOffers[$domain])) $offer_rotate[$domain] = 0;

$html = stripcslashes($html);
$redirectUrl = stripcslashes($redirectUrl);

if (strlen($html) < 10)
{
	echo "no message to send \n";
	$broadcastID--;
	continue;
}

// skip if this list and message combo already got scheduled today
foreach ($todaysbroadcasts as $tbid => $bidcontent)
{
	if ((($bidcontent["offerid"] ?? "") == $offerid) && (($bidcontent["listName"] ?? "") == $broadcastQueueJson[$listIndex]["listName"]))
	{
		echo "broadcast already scheduled, skipping ... \n";
		$broadcastID--;
		continue 2;
	}
}

$broadcastQueueJson[$listIndex]["broadcastID"] = $broadcastID;
$broadcastQueueJson[$listIndex]["bid"] = $broadcastID;
$broadcastQueueJson[$listIndex]["html"] = $html;
$broadcastQueueJson[$listIndex]["text"] = $text;
$broadcastQueueJson[$listIndex]["subject"] = $subject;
$broadcastQueueJson[$listIndex]["offerid"] = $offerid;
$broadcastQueueJson[$listIndex]["offername"] = $offername;
$broadcastQueueJson[$listIndex]["redirectUrl"] = $redirectUrl;
$broadcastQueueJson[$listIndex]["allowedStates"] = $thisOffer["allowedStates"] ?? "";
$broadcastQueueJson[$listIndex]["hoursSent"] = array();

$broadcastQueueJson[$listIndex] = ScheduleEmailRecords($baseEmailDir, $broadcastQueueJson[$listIndex], "clicker");
$broadcastQueueJson[$listIndex] = ScheduleEmailRecords($baseEmailDir, $broadcastQueueJson[$listIndex], "inactive");

if (count($broadcastQueueJson[$listIndex]["messages"]) < 1) continue;

$todaysbroadcasts[$broadcastID] = $broadcastQueueJson[$listIndex];
SaveReport($reportName, array("userdata" => $todaysbroadcasts));
$scheduled++;

} // ends loop through broadcasts

@file_put_contents($baseEmailDir . "/lastRun.txt", time());

return array("message" => "Auto scheduling finished, " . $scheduled . " broadcast(s) queued for today.");
} // ends function

// ---------------------------------------------------------------------------------

// Schedules one broadcast by hand: pick a list, a message, and a volume.
function ScheduleEmail($jsonData) {

$baseEmailDir = GetQueueDir();
$doDatabaseQuery = "0";
$lastRun = (int)@file_get_contents($baseEmailDir . "/lastRun.txt");
if ($lastRun < (time() - 86400)) $doDatabaseQuery = "1";

$broadcastQueueJson = array();

$listID = (string)($jsonData["listID"] ?? "");
$offerid = (string)($jsonData["offerid"] ?? "");
$totalToSend = (int)($jsonData["totalToSend"] ?? 0);
$redirectUrlOverride = $jsonData["redirectUrl"] ?? "";

if ((strlen($listID) < 1) || (strlen($offerid) < 1)) return array("error" => "listID and offerid are required.");

$appData = GetAppData();
if (empty($appData["messages"][$offerid])) return array("error" => "Message " . $offerid . " not found.");
if (empty($appData["lists"][$listID])) return array("error" => "List " . $listID . " not found.");

$thisOffer = $appData["messages"][$offerid];
$offername = $thisOffer["name"] ?? "";
$subject = $thisOffer["subject"] ?? "";
$redirectUrl = $thisOffer["redirectUrl"] ?? "";
$text = $thisOffer["text"] ?? "";
$html = $thisOffer["html"] ?? "";

if (strlen($redirectUrlOverride) > 10) $redirectUrl = $redirectUrlOverride;

$html = stripcslashes($html);
$redirectUrl = stripcslashes($redirectUrl);

if (strlen($html) < 10) return array("error" => "no message to send.");

$listConfig = $appData["lists"][$listID];
$domain = $listConfig["domain"] ?? "";
if (strlen($domain) < 3) return array("error" => "no sending domain on this list.");

$providerInfo = GetEmailProviderInfo($domain, $appData);
if (!$providerInfo) return array("error" => "provider not configured for " . $domain . ".");

if ($totalToSend < 1) return array("error" => "totalToSend must be at least 1.");
if ($totalToSend >= 100000) $totalToSend = 99999;

if ($doDatabaseQuery != "0") BroadcastDataQuery();

$broadcastQueueJson["messages"] = array();
$broadcastQueueJson["broadcast_date"] = date("m-d-Y");
$broadcastQueueJson["domain"] = $domain;
$broadcastQueueJson["listName"] = $listConfig["name"] ?? "";
$broadcastQueueJson["smtpHost"] = $providerInfo["smtpHost"];
$broadcastQueueJson["smtpUser"] = $providerInfo["smtpUser"];
$broadcastQueueJson["smtpPass"] = $providerInfo["smtpPass"];
$broadcastQueueJson["smtpPort"] = $providerInfo["smtpPort"];
$broadcastQueueJson["sender"] = $providerInfo["sender"];
$broadcastQueueJson["senderName"] = $providerInfo["senderName"];
$broadcastQueueJson["provider"] = $providerInfo["provider"];
$broadcastQueueJson["totalToSend"] = $totalToSend;
if (strlen($listConfig["allowedFeeds"] ?? "") > 0) $broadcastQueueJson["allowedFeeds"] = explode(",", $listConfig["allowedFeeds"]);

$broadcastQueueJson["html"] = $html;
$broadcastQueueJson["text"] = $text;
$broadcastQueueJson["subject"] = $subject;
$broadcastQueueJson["offerid"] = $offerid;
$broadcastQueueJson["offername"] = $offername;
$broadcastQueueJson["redirectUrl"] = $redirectUrl;
$broadcastQueueJson["allowedStates"] = $thisOffer["allowedStates"] ?? "";
$broadcastQueueJson["hoursSent"] = array();

$broadcastID = GetHighestBroadcastID() + 1;
$broadcastQueueJson["broadcastID"] = $broadcastID;
$broadcastQueueJson["bid"] = $broadcastID;

$broadcastQueueJson = ScheduleEmailRecords($baseEmailDir, $broadcastQueueJson, "clicker");
$broadcastQueueJson = ScheduleEmailRecords($baseEmailDir, $broadcastQueueJson, "inactive");

if (count($broadcastQueueJson["messages"]) < 1) return array("error" => "No matching contacts found for this broadcast (check filters and run the data query).");

$reportName = "broadcasts" . date("m-d-Y");
$bidData = GetReport($reportName);
if ((!$bidData) || (!isset($bidData["userdata"]))) $bidData = array("userdata" => array());
$bidData["userdata"][$broadcastID] = $broadcastQueueJson;
SaveReport($reportName, $bidData);

return array("message" => "Broadcast " . $broadcastID . " scheduled with " . count($broadcastQueueJson["messages"]) . " message(s). Cron sends each hour bucket as it comes up.", "bid" => $broadcastID);
} // ends function

// ---------------------------------------------------------------------------------

// Fills one broadcast queue from the ISP record files built by
// BroadcastDataQuery, applying state and feed filters, and spreading each
// address into an hour bucket across the business day.
function ScheduleEmailRecords($baseEmailDir, $broadcastQueueJson, $recordType = "inactive") {

$totalToSend = $broadcastQueueJson["totalToSend"] ?? 0;
$broadcastID = $broadcastQueueJson["broadcastID"] ?? 0;
$offerid = $broadcastQueueJson["offerid"] ?? "";
$currentTime = time();

$allEmailsUsed = @explode("\n", (string)@file_get_contents($baseEmailDir . "/allEmailsUsed_" . $broadcastQueueJson["domain"] . ".txt"));
if (!is_array($allEmailsUsed)) $allEmailsUsed = array();
$allISPs = array("gmail", "outlook", "yahoo", "cables", "aol", "other");
$sendCount = count($broadcastQueueJson["messages"]) - 1;

foreach ($allISPs as $thisISP) {

$handle = @fopen($baseEmailDir . "/" . $thisISP . "_" . $recordType . "Records.txt", "r");
if ($handle) {
while (!feof($handle))
{
	if ($sendCount >= ($totalToSend - 1)) break;

	$fullLine = trim(fgets($handle));
	$userdata = json_decode($fullLine, true);
	if (!$userdata) continue;

	$email = $userdata["email"] ?? "";
	if ((strlen($email) < 5) || (strpos($email, "@") === false)) continue;

	if (in_array($broadcastQueueJson["domain"], $userdata["unsubs"] ?? array())) continue;

	if (in_array($email, $allEmailsUsed)) continue;

	// State filter
	if (strlen($broadcastQueueJson["allowedStates"] ?? "") > 1)
	{
		$allowedStates = explode(",", strtoupper($broadcastQueueJson["allowedStates"]));
		if (!in_array(strtoupper($userdata["state"] ?? ""), $allowedStates)) continue;
	}

	// Feed filter
	if (is_array($broadcastQueueJson["allowedFeeds"] ?? null))
	{
		$myFeeds = explode(",", $userdata["feed"] ?? "");
		$feedFound = 0;

		foreach ($myFeeds as $thisfeed)
		{
			if (strlen($thisfeed) < 1) continue;
			if ((in_array($thisfeed, $broadcastQueueJson["allowedFeeds"])) || (in_array($userdata["sourceurl"] ?? "", $broadcastQueueJson["allowedFeeds"]))) $feedFound = 1;
		}
		if ($feedFound == 0) continue;
	}

	$message_history = explode(",", $userdata["message_history"] ?? "");
	if (strlen($message_history[0]) < 1) unset($message_history[0]);

	$allEmailsUsed[] = $email;
	$sendCount++;
	$subid = base_convert($sendCount, 10, 36) . "/" . base_convert($broadcastID, 10, 36);

	$starthour = sprintf("%d", rand(8, 17));

	$user = array("email" => $email, "starthour" => $starthour, "subid" => $subid, "fname" => $userdata["fname"] ?? "");
	$broadcastQueueJson["messages"][$sendCount] = $user;

	if (($userdata["sendCount"] ?? 0) < 1) $userdata["firstmessage"] = $currentTime;
	$userdata["lastmessage"] = $currentTime;
	$userdata["sendCount"] = ($userdata["sendCount"] ?? 0) + 1;

	$message_history[] = $offerid;
	$userdata["message_history"] = implode(",", $message_history);

	unset($userdata["email"]);
	SaveContact($email, $userdata);
}
fclose($handle);  }

} // ends loop through ISPs

@file_put_contents($baseEmailDir . "/allEmailsUsed_" . $broadcastQueueJson["domain"] . ".txt", implode("\n", $allEmailsUsed));

return $broadcastQueueJson;
} // ends function

// ---------------------------------------------------------------------------------
// Delivery webhooks. Each parser normalizes its provider's payload into
// canonical events (delivered, soft, hard, complaint, open, click, unsub),
// then the shared bookkeeping below applies them.
// ---------------------------------------------------------------------------------

function ApplyEmailWebhookEvents($events) {

if (count($events) < 1) return 0;

$bidData = GetDailyReports();
$applied = 0;

foreach ($events as $eventData)
{
	$email = CleanEmail($eventData["email"] ?? "");
	if (strlen($email) < 5) continue;
	$suppress = 0;

	switch ($eventData["event"] ?? "")
	{
		case "delivered":
			$bidData["reports"]["delivered"]++;
		break;

		case "soft":
			$bidData["reports"]["bounced"]++;
		break;

		case "hard":
			$bidData["reports"]["hard"]++;
			$suppress = 1;
		break;

		case "complaint":
			$bidData["reports"]["complaints"]++;
			$suppress = 1;
		break;

		case "open":
			$bidData["reports"]["opens"]++;
		break;

		case "click":
			$bidData["reports"]["clicks"]++;
		break;

		case "unsub":
			$bidData["reports"]["unsubs"]++;
			$suppress = 1;
		break;

		default:
			continue 2;
	}

	if ($suppress == 1) EmailUnsubFromAll(array("email" => $email));
	$applied++;
}

if ($applied > 0) SaveDailyReports($bidData);

return $applied;
} // ends function

// ---------------------------------------------------------------------------------

function EmailSendGridWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$map = array("delivered" => "delivered", "bounce" => "hard", "deferred" => "soft", "dropped" => "soft", "spamreport" => "complaint", "open" => "open", "click" => "click", "unsubscribe" => "unsub", "group_unsubscribe" => "unsub");

$events = array();
foreach ($jsonData as $eventData)
{
	$event = $eventData["event"] ?? "";
	if (!isset($map[$event])) continue;
	$events[] = array("email" => $eventData["email"] ?? "", "event" => $map[$event]);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailMailgunWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (isset($jsonData["event-data"])) $jsonData = array($jsonData["event-data"]);
else if (!isset($jsonData[0])) $jsonData = array($jsonData);

$events = array();
foreach ($jsonData as $eventData)
{
	$email = $eventData["recipient"] ?? "";
	$event = $eventData["event"] ?? "";
	$canonical = "";

	switch ($event)
	{
		case "delivered": $canonical = "delivered"; break;
		case "bounced":
		case "failed":
			$severity = $eventData["severity"] ?? "";
			$canonical = ($severity == "permanent") ? "hard" : "soft";
		break;
		case "dropped": $canonical = "soft"; break;
		case "complained": $canonical = "complaint"; break;
		case "opened": $canonical = "open"; break;
		case "clicked": $canonical = "click"; break;
		case "unsubscribed": $canonical = "unsub"; break;
	}

	if (strlen($canonical) > 0) $events[] = array("email" => $email, "event" => $canonical);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailPostmarkWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$events = array();
foreach ($jsonData as $eventData)
{
	$email = $eventData["Email"] ?? ($eventData["Recipient"] ?? "");
	$event = $eventData["RecordType"] ?? "";
	$canonical = "";

	switch ($event)
	{
		case "Delivery": $canonical = "delivered"; break;
		case "Bounce":
			$bounceType = $eventData["Type"] ?? "";
			$canonical = ($bounceType == "HardBounce") ? "hard" : "soft";
		break;
		case "SpamComplaint": $canonical = "complaint"; break;
		case "Open": $canonical = "open"; break;
		case "Click": $canonical = "click"; break;
		case "SubscriptionChange": $canonical = "unsub"; break;
	}

	if (strlen($canonical) > 0) $events[] = array("email" => $email, "event" => $canonical);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailSESWebhook($jsonData) {

if (!is_array($jsonData)) return 0;

// SNS wraps the SES event in a Message string
if (isset($jsonData["Message"])) {
	$decoded = json_decode($jsonData["Message"], true);
	if (is_array($decoded)) $jsonData = array($decoded);
	else return 0;
} else if (!isset($jsonData[0])) $jsonData = array($jsonData);

$events = array();
foreach ($jsonData as $eventData)
{
	$event = $eventData["eventType"] ?? ($eventData["notificationType"] ?? "");
	$email = "";
	$canonical = "";

	if ($event == "Delivery") {
		$email = $eventData["delivery"]["recipients"][0] ?? "";
		$canonical = "delivered";
	}

	if ($event == "Bounce") {
		$email = $eventData["bounce"]["bouncedRecipients"][0]["emailAddress"] ?? "";
		$bounceType = $eventData["bounce"]["bounceType"] ?? "";
		$canonical = ($bounceType == "Permanent") ? "hard" : "soft";
	}

	if ($event == "Complaint") {
		$email = $eventData["complaint"]["complainedRecipients"][0]["emailAddress"] ?? "";
		$canonical = "complaint";
	}

	if ($event == "Open") {
		$email = $eventData["mail"]["destination"][0] ?? "";
		$canonical = "open";
	}

	if ($event == "Click") {
		$email = $eventData["mail"]["destination"][0] ?? "";
		$canonical = "click";
	}

	if (strlen($canonical) > 0) $events[] = array("email" => $email, "event" => $canonical);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailSMTP2GOWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$events = array();
foreach ($jsonData as $eventData)
{
	$email = $eventData["email"] ?? ($eventData["rcpt"] ?? "");
	$event = $eventData["event"] ?? "";
	$canonical = "";

	switch ($event)
	{
		case "delivered": $canonical = "delivered"; break;
		case "bounce":
			$bounceType = $eventData["bounce_type"] ?? "";
			$canonical = ($bounceType == "hard") ? "hard" : "soft";
		break;
		case "spam": $canonical = "complaint"; break;
		case "open": $canonical = "open"; break;
		case "click": $canonical = "click"; break;
		case "unsubscribe": $canonical = "unsub"; break;
	}

	if (strlen($canonical) > 0) $events[] = array("email" => $email, "event" => $canonical);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailBrevoWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$map = array("delivered" => "delivered", "hard_bounce" => "hard", "soft_bounce" => "soft", "spam" => "complaint", "opened" => "open", "unique_opened" => "open", "click" => "click", "unsubscribed" => "unsub", "blocked" => "hard");

$events = array();
foreach ($jsonData as $eventData)
{
	$event = $eventData["event"] ?? "";
	if (!isset($map[$event])) continue;
	$events[] = array("email" => $eventData["email"] ?? "", "event" => $map[$event]);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailSparkPostWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (isset($jsonData["msys"])) $jsonData = array($jsonData);

$events = array();
foreach ($jsonData as $wrapper)
{
	$msys = $wrapper["msys"] ?? array();
	$eventGroup = reset($msys);
	if (!is_array($eventGroup)) continue;

	$event = $eventGroup["type"] ?? key($msys);
	$email = $eventGroup["rcpt_to"] ?? "";
	$canonical = "";

	switch ($event)
	{
		case "delivery":
		case "message_event":
			$canonical = "delivered";
		break;
		case "bounce":
			$bounceClass = (int)($eventGroup["bounce_class"] ?? 0);
			$canonical = ($bounceClass >= 90) ? "hard" : "soft";
		break;
		case "spam_complaint": $canonical = "complaint"; break;
		case "open":
		case "initial_open":
			$canonical = "open";
		break;
		case "click": $canonical = "click"; break;
		case "list_unsubscribe":
		case "link_unsubscribe":
			$canonical = "unsub";
		break;
	}

	if (strlen($canonical) > 0) $events[] = array("email" => $email, "event" => $canonical);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailElasticEmailWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$map = array("sent" => "delivered", "error" => "hard", "bounce" => "soft", "spam" => "complaint", "opened" => "open", "clicked" => "click", "unsubscribed" => "unsub", "abusereport" => "complaint");

$events = array();
foreach ($jsonData as $eventData)
{
	$event = strtolower($eventData["status"] ?? "");
	if (!isset($map[$event])) continue;
	$events[] = array("email" => $eventData["to"] ?? "", "event" => $map[$event]);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailMailtrapWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (isset($jsonData["events"])) $jsonData = $jsonData["events"];
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$map = array("delivery" => "delivered", "delivered" => "delivered", "bounce" => "soft", "soft bounce" => "soft", "spam" => "complaint", "open" => "open", "click" => "click", "unsubscribe" => "unsub", "suspension" => "hard", "reject" => "hard");

$events = array();
foreach ($jsonData as $eventData)
{
	$event = strtolower($eventData["event"] ?? "");
	if (!isset($map[$event])) continue;
	$events[] = array("email" => $eventData["email"] ?? "", "event" => $map[$event]);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailMailjetWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$events = array();
foreach ($jsonData as $eventData)
{
	$email = $eventData["email"] ?? "";
	$event = $eventData["event"] ?? "";
	$canonical = "";

	switch ($event)
	{
		case "sent": $canonical = "delivered"; break;
		case "bounce":
			$hardBounce = $eventData["hard_bounce"] ?? false;
			$canonical = ($hardBounce) ? "hard" : "soft";
		break;
		case "blocked": $canonical = "hard"; break;
		case "spam": $canonical = "complaint"; break;
		case "open": $canonical = "open"; break;
		case "click": $canonical = "click"; break;
		case "unsub": $canonical = "unsub"; break;
	}

	if (strlen($canonical) > 0) $events[] = array("email" => $email, "event" => $canonical);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------

function EmailSMTPComWebhook($jsonData) {

if (!is_array($jsonData)) return 0;
if (!isset($jsonData[0])) $jsonData = array($jsonData);

$map = array("delivered" => "delivered", "hard_bounce" => "hard", "soft_bounce" => "soft", "spam_complaint" => "complaint", "open" => "open", "click" => "click", "unsubscribe" => "unsub");

$events = array();
foreach ($jsonData as $eventData)
{
	$event = strtolower($eventData["event"] ?? "");
	if (!isset($map[$event])) continue;
	$events[] = array("email" => $eventData["email"] ?? "", "event" => $map[$event]);
}

return ApplyEmailWebhookEvents($events);
} // ends function

// ---------------------------------------------------------------------------------
// Click tracking and bot detection
// ---------------------------------------------------------------------------------

function ClickDetectBotCheap($browser) {

$isBot = 0;
$browser = strtolower((string)$browser);
if (substr_count($browser, "google.com") > 0) $isBot = 1;
if (substr_count($browser, "go-http-client") > 0) $isBot = 1;
if (substr_count($browser, "bingbot") > 0) $isBot = 1;
if (substr_count($browser, "slurp") > 0) $isBot = 1;
if (substr_count($browser, "wget") > 0) $isBot = 1;
if (substr_count($browser, "curl/") > 0) $isBot = 1;
if (substr_count($browser, "linkedin.com") > 0) $isBot = 1;
if (substr_count($browser, "python-requests") > 0) $isBot = 1;
if (substr_count($browser, "okhttp") > 0) $isBot = 1;
if (substr_count($browser, "node-fetch") > 0) $isBot = 1;
if (substr_count($browser, "facebook.com/externalhit_uatext.php") > 0) $isBot = 1;

return $isBot;
} // ends function

// ---------------------------------------------------------------------------------

// Optional LLM check on a user agent string. Needs botDetectProvider set in
// config.php, returns "no" when disabled so clicks always pass.
function ClickDetectBotGPT($browser) {

$config = GetConfig();
$provider = strtolower($config["botDetectProvider"] ?? "off");
if (($provider != "anthropic") && ($provider != "openai")) return "no";

$rules = "You are a website bot detection assistant checking a browser string to detect if it is most likely a bot program and not a real person. Return no text at all except for yes if it is a bot, or no if it is not a bot. If you are not sure, say no.";
$prompt = "does this browser string look like a real person or a bot? " . $browser;

$response = "";

if ($provider == "anthropic") {
$payload = json_encode(array("model" => $config["anthropicModel"] ?? "claude-haiku-4-5-20251001", "max_tokens" => 10, "system" => $rules, "messages" => array(array("role" => "user", "content" => $prompt))));
$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_HTTPHEADER => array("Content-Type: application/json", "x-api-key: " . ($config["anthropicApiKey"] ?? ""), "anthropic-version: 2023-06-01"), CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15));
$result = json_decode(curl_exec($ch), true);
curl_close($ch);
$response = $result["content"][0]["text"] ?? "";  }

else {
$payload = json_encode(array("model" => $config["openaiModel"] ?? "gpt-4o-mini", "max_tokens" => 10, "messages" => array(array("role" => "system", "content" => $rules), array("role" => "user", "content" => $prompt))));
$ch = curl_init(rtrim($config["openaiBaseUrl"] ?? "https://api.openai.com/v1", "/") . "/chat/completions");
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_HTTPHEADER => array("Content-Type: application/json", "Authorization: Bearer " . ($config["openaiApiKey"] ?? "")), CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15));
$result = json_decode(curl_exec($ch), true);
curl_close($ch);
$response = $result["choices"][0]["message"]["content"] ?? "";  }

$response = strtolower(trim($response));
if (substr_count($response, "yes") > 0) return "yes";
return "no";
} // ends function

// ---------------------------------------------------------------------------------

// Resolves a tracked click and returns the URL to redirect to. Records the
// click, upgrades the contact to clicker, and filters bots.
// Path format: click.php/{u}/{b} where the message ##SUBID## was u/b.
// Broadcast clicks: u = user index base36, b = broadcast id base36.
// Responder clicks: u = messageID, b = series-index.
function EmailClickRedirect($bUserID, $broadcastID, $browser = "") {

$config = GetConfig();
$appData = GetAppData();
$defaultRedirect = $config["defaultRedirect"] ?? "";
if (strlen($defaultRedirect) < 10) $defaultRedirect = "https://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . "/";
$clickbotdetect = $appData["settings"]["clickBotDetect"] ?? "0";

$isBot = ClickDetectBotCheap($browser);
if ($isBot == 1) return $defaultRedirect;


if (substr_count($broadcastID, "-") > 0) { // autoresponder click

$temp = explode("-", $broadcastID);
$series = $temp[0];
$seriesIndex = $temp[1] ?? "";
$messageID = $bUserID;
$subid = $messageID . "_" . $series . "_" . $seriesIndex;

if ($clickbotdetect == "1") {
$isBot = ClickDetectBotGPT($browser);
if ($isBot == "yes") return $defaultRedirect;  }

$messages = $appData["messages"] ?? array();
if (!isset($messages[$messageID])) return $defaultRedirect;

$redirectUrl = $messages[$messageID]["redirectUrl"] ?? "";
$redirectUrl = stripcslashes($redirectUrl);
$redirectUrl = str_replace("##SUBID##", $subid, $redirectUrl);

if (strlen($redirectUrl) < 10) $redirectUrl = $defaultRedirect;

if (strlen($series) > 0) {
$arData = GetReport("responders" . date("m-d-Y"));
if (!$arData) $arData = array();
if (!isset($arData["reports"][$series])) $arData["reports"][$series] = array("clicks" => 0);
if (!isset($arData["reports"][$series]["clicks"])) $arData["reports"][$series]["clicks"] = 0;
$arData["reports"][$series]["clicks"]++;
SaveReport("responders" . date("m-d-Y"), $arData);  }

return $redirectUrl;  }


else { // broadcast click
$bUserID = base_convert($bUserID, 36, 10);
$broadcastID = base_convert($broadcastID, 36, 10);
$subid = $bUserID . "_" . $broadcastID;

if ($clickbotdetect == "1") {
$isBot = ClickDetectBotGPT($browser);
if ($isBot == "yes") return $defaultRedirect;  }

$bidData = GetReport("broadcasts" . date("m-d-Y"));
if ((!$bidData) || (empty($bidData["userdata"][$broadcastID]))) return $defaultRedirect;
$broadcastQueueJson = $bidData["userdata"][$broadcastID];

$refid = $broadcastQueueJson["refid"] ?? "";
$email = $broadcastQueueJson["messages"][$bUserID]["email"] ?? "";

$redirectUrl = stripcslashes($broadcastQueueJson["redirectUrl"] ?? "");
$redirectUrl = str_replace("##SUBID##", $subid, $redirectUrl);
$redirectUrl = str_replace("##REFID##", $refid, $redirectUrl);

if (strlen($redirectUrl) < 10) $redirectUrl = $defaultRedirect;

if (strlen($email) < 5) return $redirectUrl;
if ($broadcastID < 1) return $redirectUrl;

$dailyData = GetDailyReports();
$dailyData["reports"]["clicks"]++;
SaveDailyReports($dailyData);

$userdata = GetContact($email);
if ($userdata) {
if (($userdata["activity"] ?? "") != "a") $userdata["activity"] = "c";
$userdata["lastClick"] = time();
SaveContact($email, $userdata);  }

return $redirectUrl;  }

} // ends function

// ---------------------------------------------------------------------------------

function EmailMarkRecordAsConverter($jsonData = array()) {

$email = CleanEmail($jsonData["email"] ?? "");
if (strlen($email) < 5) return array("error" => "email is invalid.");

$userdata = GetContact($email);
if (!$userdata) return array("error" => "email not found.");
if (($userdata["activity"] ?? "") != "a") {
$userdata["activity"] = "a";
$userdata["lastConvert"] = time();
SaveContact($email, $userdata);  }

return array("message" => "Marked " . $email . " as a converter.");
} // ends function

// ---------------------------------------------------------------------------------

function RecordEmailBroadcastUnsub($jsonData) {

$email = CleanEmail($jsonData["email"] ?? "");
if (strlen($email) < 5) return false;
$domain = $jsonData["domain"] ?? "";

$userdata = GetContact($email);
if (!$userdata) $userdata = array();
else {
if (strlen($domain) > 3)
{
	if (!isset($userdata["unsubs"])) $userdata["unsubs"] = array();
	if (!in_array($domain, $userdata["unsubs"])) $userdata["unsubs"][] = $domain;
}
$userdata["removeDate"] = time();
DeleteContact($email);  }

if (!GetSuppressed($email)) SaveSuppressed($email, $userdata);

return true;
} // ends function

// ---------------------------------------------------------------------------------
// Contact intake and profile helpers
// ---------------------------------------------------------------------------------

function PostEmailBroadcastData($postData) {

$allISPs = array("gmail", "outlook", "yahoo", "cables", "aol", "other");

$email = CleanEmail(urldecode($postData['email'] ?? ""));
$feed = urldecode($postData['feed'] ?? "");
$optin = urldecode($postData['optin'] ?? "");
$ip = urldecode($postData['ip'] ?? "");
$sourceurl = urldecode($postData['sourceurl'] ?? "");
$fname = urldecode(ucwords(strtolower($postData['fname'] ?? "")));
$lname = urldecode(ucwords(strtolower($postData['lname'] ?? "")));
$country = urldecode($postData['country'] ?? "");
$state = urldecode($postData['state'] ?? "");
$ISP = urldecode($postData['ISP'] ?? "");

if (!in_array($ISP, $allISPs)) $ISP = "other";
$sourceurl = str_replace("https://", "", $sourceurl);
$sourceurl = str_replace("http://", "", $sourceurl);
$sourceurl = str_replace("www.", "", $sourceurl);

$ISP = TranslateISPToGroup($email, $ISP);

if (strlen($email) < 5) return array("error" => "email is invalid.");
if (strlen($country) < 2) $country = "US";
if (strlen($feed) < 1) $feed = "Default_Feed";

$suppressed = GetSuppressed($email);
if (!$suppressed) {

if (GetContact($email)) return array("error" => "email already exists.");

$userdata = array();
$userdata["ISP"] = $ISP;
$userdata["sourceurl"] = $sourceurl;
$userdata["optin"] = $optin;
$userdata["fname"] = $fname;
$userdata["lname"] = $lname;
$userdata["state"] = $state;
$userdata["country"] = $country;
$userdata["ip"] = $ip;
$userdata["feed"] = $feed;
$userdata["created"] = time();

SaveContact($email, $userdata);
return array("message" => "Contact added.");  }

else if (($suppressed["pruned"] ?? 0) == 1) {

unset($suppressed["pruned"]);
unset($suppressed["removeDate"]);
$suppressed["reImportDate"] = time();

DeleteSuppressed($email);
SaveContact($email, $suppressed);
return array("message" => "Contact restored from the pruned list.");  }

return array("error" => "email is unsubscribed.");
} // ends function

// ---------------------------------------------------------------------------------

function TranslateISPToGroup($email, $tempISP = "") {

$domain = "";
if (strpos($email, "@") !== false) $domain = strtolower(substr(strrchr($email, "@"), 1));

switch ($domain)
{
	case "gmail.com":
		$ISP = "gmail";
	break;
	case "outlook.com":
	case "hotmail.com":
	case "msn.com":
	case "live.com":
		$ISP = "outlook";
	break;
	case "yahoo.com":
	case "yahoo.co.uk":
		$ISP = "yahoo";
	break;
	case "aol.com":
		$ISP = "aol";
	break;
	case "comcast.net":
	case "xfinity.com":
	case "cox.net":
	case "charter.net":
	case "spectrum.net":
	case "verizon.net":
	case "att.net":
		$ISP = "cables";
	break;
	default:
		$ISP = "other";
	break;
}

if (($ISP == "other") && (strlen($tempISP) > 0)) $ISP = $tempISP;

return $ISP;
} // ends function

// ---------------------------------------------------------------------------------
// Chatbot hook. Forward a reply to the chat API from config.php and email the
// answer back from the given domain.

function ProcessChatbotResponse($email, $domain, $message, $chatbotID = "") {

$config = GetConfig();
$chatbotApiUrl = $config["chatbotApiUrl"] ?? "";
if (strlen($chatbotApiUrl) < 10) return array("error" => "chatbotApiUrl is not set in config.php.");

$email = CleanEmail($email);
if (strlen($email) < 5) return array("error" => "email is invalid.");

if (strlen($chatbotID) < 1) {
	$domainEntry = GetDomainEntry($domain);
	$chatbotID = $domainEntry["chatbotID"] ?? "";
}
if (strlen($chatbotID) < 1) return array("error" => "no chatbotID set for this domain.");

$chatData = array("chatbotID" => $chatbotID, "convoID" => $domain . "-" . $email, "prompt" => $message, "userInfo" => array("channel" => "email", "email" => $email, "domain" => $domain));

$ch = curl_init($chatbotApiUrl);
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_HTTPHEADER => array("Content-Type: application/json", "X-API-Key: " . ($config["chatbotApiKey"] ?? "")), CURLOPT_POSTFIELDS => json_encode($chatData), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60));
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($response["message"])) return array("error" => "the chatbot returned no message.");

$sendResult = SendEmailMessage(array("email" => $email, "domain" => $domain, "subject" => "Re: your message", "html" => nl2br(htmlspecialchars($response["message"])), "text" => $response["message"]));
if (strlen($sendResult["error"] ?? "") > 0) return $sendResult;

return array("message" => "Reply sent to " . $email . ".");
} // ends function

?>
