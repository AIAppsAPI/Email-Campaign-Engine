<?php

session_start();

require_once __DIR__ . "/../lib/email.php";

// Admin area: sending domains, messages, lists, responder series, contact
// uploads, reports, and settings. Login uses adminPassword from config.php.

$config = GetConfig();
$adminPassword = (string)($config["adminPassword"] ?? "");

function h($text) { return htmlspecialchars((string)$text, ENT_QUOTES, "UTF-8"); }

$notice = "";
$noticeError = "";

// ---------------------------------------------------------------------------------
// Login and logout

if (($_GET["do"] ?? "") == "logout") {
	$_SESSION = array();
	session_destroy();
	header("Location: admin.php");
	exit;
}

if (($_POST["do"] ?? "") == "login") {
	if (strlen($adminPassword) < 8) $noticeError = "Set adminPassword in config.php (at least 8 characters) before logging in.";
	else if (hash_equals($adminPassword, (string)($_POST["password"] ?? ""))) {
		session_regenerate_id(true);
		$_SESSION["emailAdmin"] = 1;
		$_SESSION["csrf"] = bin2hex(random_bytes(16));
		header("Location: admin.php");
		exit;
	}
	else $noticeError = "Wrong password.";
}

$loggedIn = !empty($_SESSION["emailAdmin"]);

// ---------------------------------------------------------------------------------
// Shared page style

function PageTop($title, $loggedIn) {
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($title); ?></title>
<style>
body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #f4f5f7; color: #222; }
.topbar { background: #1f2733; color: #fff; padding: 12px 20px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
.topbar strong { font-size: 18px; }
.topbar a { color: #cfd8e3; text-decoration: none; padding: 4px 8px; border-radius: 4px; }
.topbar a:hover { background: #334052; color: #fff; }
.wrap { max-width: 980px; margin: 20px auto; padding: 0 16px; }
.card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 18px; margin-bottom: 18px; }
.card h2 { margin-top: 0; font-size: 18px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
input[type=text], input[type=password], input[type=number], input[type=url], select, textarea {
	width: 100%; padding: 8px; margin: 4px 0 12px; box-sizing: border-box; border: 1px solid #bbb; border-radius: 5px; font-size: 14px; }
textarea { font-family: inherit; }
label { font-weight: bold; font-size: 13px; }
button { background: #2b6cb0; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; }
button:hover { background: #234f80; }
button.danger { background: #b03030; }
button.danger:hover { background: #8a2424; }
.notice { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
.notice.ok { background: #e2f2e4; border: 1px solid #9ec9a3; }
.notice.err { background: #f7e3e3; border: 1px solid #d3a2a2; }
.mono { font-family: monospace; font-size: 13px; }
.small { font-size: 12px; color: #666; }
.inlineform { display: inline; }
.statgrid { display: flex; gap: 14px; flex-wrap: wrap; }
.stat { background: #f8f9fb; border: 1px solid #e2e2e2; border-radius: 6px; padding: 12px 18px; min-width: 110px; }
.stat b { display: block; font-size: 22px; }
.stat span { font-size: 12px; color: #666; }
</style></head><body>
<div class="topbar"><strong>Email Campaign Engine</strong>
<?php if ($loggedIn) { ?>
<a href="admin.php?page=dashboard">Dashboard</a>
<a href="admin.php?page=domains">Domains</a>
<a href="admin.php?page=messages">Messages</a>
<a href="admin.php?page=lists">Lists</a>
<a href="admin.php?page=series">Series</a>
<a href="admin.php?page=contacts">Contacts</a>
<a href="admin.php?page=settings">Settings</a>
<a href="admin.php?do=logout">Log out</a>
<?php } ?>
</div><div class="wrap">
<?php
} // ends function

function PageBottom() {
echo "</div></body></html>";
} // ends function

// ---------------------------------------------------------------------------------
// Not logged in: show the login page and stop

if (!$loggedIn) {
	PageTop("Email Campaign Engine Login", false);
	if (strlen($noticeError) > 0) echo "<div class='notice err'>" . h($noticeError) . "</div>";
	?>
	<div class="card" style="max-width:420px;margin:40px auto;">
	<h2>Admin Login</h2>
	<form method="post" action="admin.php">
	<input type="hidden" name="do" value="login">
	<label>Password</label>
	<input type="password" name="password" autofocus>
	<button type="submit">Log in</button>
	</form>
	</div>
	<?php
	PageBottom();
	exit;
}

$csrf = $_SESSION["csrf"] ?? "";
if (strlen($csrf) < 10) { $_SESSION["csrf"] = bin2hex(random_bytes(16)); $csrf = $_SESSION["csrf"]; }

// ---------------------------------------------------------------------------------
// Handle POST actions

if (($_SERVER["REQUEST_METHOD"] ?? "") == "POST" && ($_POST["do"] ?? "") != "login") {

if (!hash_equals($csrf, (string)($_POST["csrf"] ?? ""))) {
	$noticeError = "Invalid form token, please try again.";
} else {

$appData = GetAppData();

switch ($_POST["do"] ?? "")
{
	case "savedomain":
		$domain = strtolower(trim((string)($_POST["domain"] ?? "")));
		if (strlen($domain) < 4) { $noticeError = "Domain is required."; break; }

		$entry = array(
			"domain" => $domain,
			"alias" => trim((string)($_POST["alias"] ?? "")),
			"senderName" => trim((string)($_POST["senderName"] ?? "")),
			"smtpHost" => trim((string)($_POST["smtpHost"] ?? "")),
			"smtpUser" => trim((string)($_POST["smtpUser"] ?? "")),
			"smtpPass" => (string)($_POST["smtpPass"] ?? ""),
			"smtpPort" => (int)($_POST["smtpPort"] ?? 587),
			"provider" => (string)($_POST["provider"] ?? ""),
			"chatbotID" => trim((string)($_POST["chatbotID"] ?? "")),
		);

		$found = 0;
		foreach ($appData["domains"] as $index => $domainEntry) {
			if (($domainEntry["domain"] ?? "") == $domain) {
				if (strlen($entry["smtpPass"]) < 1) $entry["smtpPass"] = $domainEntry["smtpPass"] ?? "";
				$appData["domains"][$index] = $entry;
				$found = 1;
			}
		}
		if ($found == 0) $appData["domains"][] = $entry;
		SaveAppData($appData);
		$notice = "Domain " . $domain . " saved.";
	break;

	case "deletedomain":
		$domain = (string)($_POST["domain"] ?? "");
		foreach ($appData["domains"] as $index => $domainEntry) {
			if (($domainEntry["domain"] ?? "") == $domain) unset($appData["domains"][$index]);
		}
		$appData["domains"] = array_values($appData["domains"]);
		SaveAppData($appData);
		$notice = "Domain " . $domain . " deleted.";
	break;

	case "savemessage":
		$messageID = (string)($_POST["messageID"] ?? "");
		if (strlen($messageID) < 1) {
			$highest = 0;
			foreach ($appData["messages"] as $mid => $msg) { if ((int)$mid > $highest) $highest = (int)$mid; }
			$messageID = (string)($highest + 1);
		}

		$arstatus = array();
		for ($d = 0; $d < 7; $d++) $arstatus[] = (($_POST["ar" . $d] ?? "0") == "1") ? "1" : "0";

		$appData["messages"][$messageID] = array(
			"name" => trim((string)($_POST["name"] ?? "")),
			"subject" => (string)($_POST["subject"] ?? ""),
			"html" => (string)($_POST["html"] ?? ""),
			"text" => (string)($_POST["text"] ?? ""),
			"redirectUrl" => trim((string)($_POST["redirectUrl"] ?? "")),
			"domain" => strtolower(trim((string)($_POST["domain"] ?? ""))),
			"allowedStates" => trim((string)($_POST["allowedStates"] ?? "")),
			"arstatus" => implode(",", $arstatus),
		);
		SaveAppData($appData);
		$notice = "Message " . $messageID . " saved.";
	break;

	case "deletemessage":
		$messageID = (string)($_POST["messageID"] ?? "");
		unset($appData["messages"][$messageID]);
		SaveAppData($appData);
		$notice = "Message " . $messageID . " deleted.";
	break;

	case "savelist":
		$listID = preg_replace("/[^a-zA-Z0-9_\-]/", "", (string)($_POST["listID"] ?? ""));
		if (strlen($listID) < 1) { $noticeError = "List ID is required (letters, numbers, dashes, underscores)."; break; }

		$appData["lists"][$listID] = array(
			"name" => trim((string)($_POST["name"] ?? $listID)),
			"domain" => strtolower(trim((string)($_POST["domain"] ?? ""))),
			"totalVolume" => (int)($_POST["totalVolume"] ?? 0),
			"allowedFeeds" => trim((string)($_POST["allowedFeeds"] ?? "")),
		);
		SaveAppData($appData);
		$notice = "List " . $listID . " saved.";
	break;

	case "deletelist":
		$listID = (string)($_POST["listID"] ?? "");
		unset($appData["lists"][$listID]);
		SaveAppData($appData);
		$notice = "List " . $listID . " deleted.";
	break;

	case "saveseries":
		$seriesID = preg_replace("/[^a-zA-Z0-9_\-]/", "", (string)($_POST["seriesID"] ?? ""));
		if (strlen($seriesID) < 1) { $noticeError = "Series ID is required (letters, numbers, dashes, underscores)."; break; }

		$messageList = array();
		foreach (explode(",", (string)($_POST["messageList"] ?? "")) as $mid) { $mid = trim($mid); if (strlen($mid) > 0) $messageList[] = $mid; }

		$exceptHours = array();
		foreach (explode(",", (string)($_POST["exceptHours"] ?? "")) as $hr) { $hr = trim($hr); if (strlen($hr) > 0) $exceptHours[] = (int)$hr; }

		$exceptDays = array();
		foreach (explode(",", (string)($_POST["exceptDays"] ?? "")) as $dy) { $dy = trim($dy); if (strlen($dy) > 0) $exceptDays[] = (int)$dy; }

		$freq = (string)($_POST["freq"] ?? "daily");
		if (!in_array($freq, array("daily", "weekly", "monthly"))) $freq = "daily";

		$appData["series"][$seriesID] = array(
			"domain" => strtolower(trim((string)($_POST["domain"] ?? ""))),
			"freq" => $freq,
			"messageList" => $messageList,
			"exceptHours" => $exceptHours,
			"exceptDays" => $exceptDays,
		);
		SaveAppData($appData);
		$notice = "Series " . $seriesID . " saved.";
	break;

	case "deleteseries":
		$seriesID = (string)($_POST["seriesID"] ?? "");
		unset($appData["series"][$seriesID]);
		SaveAppData($appData);
		$notice = "Series " . $seriesID . " deleted.";
	break;

	case "savesettings":
		$appData["settings"]["pruningCount"] = (int)($_POST["pruningCount"] ?? 0);
		$appData["settings"]["daysSinceClick"] = (int)($_POST["daysSinceClick"] ?? 30);
		$appData["settings"]["scheduleStatus"] = (($_POST["scheduleStatus"] ?? "0") == "1") ? "1" : "0";
		$appData["settings"]["autoScheduleHour"] = (int)($_POST["autoScheduleHour"] ?? 7);
		$appData["settings"]["clickBotDetect"] = (($_POST["clickBotDetect"] ?? "0") == "1") ? "1" : "0";
		SaveAppData($appData);
		$notice = "Settings saved.";
	break;

	case "uploadcontacts":
		set_time_limit(0);
		$result = UploadEmailData(array("content" => (string)($_POST["content"] ?? "")));
		$notice = $result["message"] ?? "Upload finished.";
	break;

	case "uploadresponders":
		set_time_limit(0);
		$result = UploadEmailResponders(array("content" => (string)($_POST["content"] ?? ""), "series" => (string)($_POST["series"] ?? "")));
		$notice = $result["message"] ?? "Upload finished.";
	break;

	case "uploadunsubs":
		set_time_limit(0);
		$result = UploadEmailUnsubs(array("content" => (string)($_POST["content"] ?? "")));
		$notice = $result["message"] ?? "Upload finished.";
	break;

	case "unsubcontact":
		$done = EmailUnsubFromAll(array("email" => (string)($_POST["email"] ?? "")));
		if ($done) $notice = "Unsubscribed " . CleanEmail($_POST["email"] ?? "") . " from everything.";
		else $noticeError = "Email is invalid.";
	break;

	case "sendtest":
		$result = SendEmailMessage(array(
			"email" => (string)($_POST["email"] ?? ""),
			"domain" => (string)($_POST["domain"] ?? ""),
			"subject" => "Email Campaign Engine test",
			"html" => "<p>This is a test message from your Email Campaign Engine install. If you are reading this, SMTP works.</p>",
		));
		if (strlen($result["error"] ?? "") > 0) $noticeError = "Test failed: " . $result["error"];
		else $notice = "Test message sent, check the inbox (and the spam folder).";
	break;

	case "schedulebroadcast":
		set_time_limit(0);
		$result = ScheduleEmail(array(
			"listID" => (string)($_POST["listID"] ?? ""),
			"offerid" => (string)($_POST["offerid"] ?? ""),
			"totalToSend" => (int)($_POST["totalToSend"] ?? 0),
			"redirectUrl" => trim((string)($_POST["redirectUrl"] ?? "")),
		));
		if (strlen($result["error"] ?? "") > 0) $noticeError = $result["error"];
		else $notice = $result["message"] ?? "Broadcast scheduled.";
	break;

	case "autoschedule":
		set_time_limit(0);
		$result = AutoScheduleEmail();
		if (strlen($result["error"] ?? "") > 0) $noticeError = $result["error"];
		else $notice = $result["message"] ?? "Auto scheduling finished.";
	break;

	case "dataquery":
		set_time_limit(0);
		BroadcastDataQuery();
		$notice = "Data query finished, queue files and counts rebuilt.";
	break;
}
}
}

// ---------------------------------------------------------------------------------
// Render the requested page

$appData = GetAppData();
$page = (string)($_GET["page"] ?? "dashboard");
PageTop("Email Campaign Engine Admin", true);

if (strlen($notice) > 0) echo "<div class='notice ok'>" . h($notice) . "</div>";
if (strlen($noticeError) > 0) echo "<div class='notice err'>" . h($noticeError) . "</div>";

$dayNames = array("Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat");

// ---------------------------------------------------------------------------------

if ($page == "dashboard") {

$date = preg_replace("/[^0-9\-]/", "", (string)($_GET["date"] ?? date("m-d-Y")));
if (strlen($date) < 8) $date = date("m-d-Y");
$daily = GetDailyReports($date);
$datacount = GetReport("datacount" . $date);
$broadcasts = GetReport("broadcasts" . $date);
?>
<div class="card">
<h2>Reports for <?php echo h($date); ?></h2>
<form method="get" action="admin.php" style="max-width:280px;">
<input type="hidden" name="page" value="dashboard">
<label>Date (m-d-Y)</label>
<input type="text" name="date" value="<?php echo h($date); ?>">
<button type="submit">View</button>
</form>
<div class="statgrid">
<div class="stat"><b><?php echo (int)$daily["reports"]["delivered"]; ?></b><span>delivered</span></div>
<div class="stat"><b><?php echo (int)$daily["reports"]["opens"]; ?></b><span>opens</span></div>
<div class="stat"><b><?php echo (int)$daily["reports"]["clicks"]; ?></b><span>clicks</span></div>
<div class="stat"><b><?php echo (int)$daily["reports"]["bounced"]; ?></b><span>soft bounces</span></div>
<div class="stat"><b><?php echo (int)$daily["reports"]["hard"]; ?></b><span>hard bounces</span></div>
<div class="stat"><b><?php echo (int)$daily["reports"]["complaints"]; ?></b><span>complaints</span></div>
<div class="stat"><b><?php echo (int)$daily["reports"]["unsubs"]; ?></b><span>unsubs</span></div>
<div class="stat"><b><?php echo CountContacts(); ?></b><span>contacts</span></div>
<div class="stat"><b><?php echo CountSuppressed(); ?></b><span>suppressed</span></div>
</div>
<?php if ($datacount) { ?>
<p class="small">Last data query: <?php echo h($datacount["lastQueried"] ?? ""); ?> |
Converters: <?php echo (int)($datacount["totalConverters"] ?? 0); ?> |
Clickers: <?php echo (int)($datacount["totalClickers"] ?? 0); ?> |
Inactive: <?php echo (int)($datacount["totalInactive"] ?? 0); ?></p>
<?php } ?>
</div>

<div class="card">
<h2>Broadcasts on <?php echo h($date); ?></h2>
<?php if ((!$broadcasts) || (empty($broadcasts["userdata"]))) echo "<p>No broadcasts scheduled for this date.</p>"; else { ?>
<table><tr><th>ID</th><th>List</th><th>Message</th><th>Queued</th><th>Hours sent</th></tr>
<?php foreach ($broadcasts["userdata"] as $bid => $bidcontent) { ?>
<tr>
<td class="mono"><?php echo h($bid); ?></td>
<td><?php echo h($bidcontent["listName"] ?? ""); ?></td>
<td><?php echo h($bidcontent["offername"] ?? ""); ?></td>
<td><?php echo count($bidcontent["messages"] ?? array()); ?></td>
<td class="mono"><?php echo h(implode(",", $bidcontent["hoursSent"] ?? array())); ?></td>
</tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="card">
<h2>Schedule a Broadcast</h2>
<form method="post" action="admin.php?page=dashboard">
<input type="hidden" name="do" value="schedulebroadcast"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<label>List</label>
<select name="listID">
<?php foreach ($appData["lists"] as $listID => $listData) echo "<option value='" . h($listID) . "'>" . h($listData["name"] ?? $listID) . "</option>"; ?>
</select>
<label>Message</label>
<select name="offerid">
<?php foreach ($appData["messages"] as $mid => $msg) echo "<option value='" . h($mid) . "'>" . h($mid . ": " . ($msg["name"] ?? "")) . "</option>"; ?>
</select>
<label>Total to send</label>
<input type="number" name="totalToSend" min="1" max="99999" value="1000">
<label>Redirect URL override (optional)</label>
<input type="text" name="redirectUrl" value="">
<button type="submit">Schedule Now</button>
</form>
<p class="small">Cron sends each hour bucket as it comes up. Run the data query first if your contact list changed today.</p>
<form class="inlineform" method="post" action="admin.php?page=dashboard">
<input type="hidden" name="do" value="dataquery"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<button type="submit">Run Data Query</button></form>
<form class="inlineform" method="post" action="admin.php?page=dashboard">
<input type="hidden" name="do" value="autoschedule"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<button type="submit">Run Auto Scheduler Now</button></form>
</div>

<div class="card">
<h2>Send a Test Email</h2>
<form method="post" action="admin.php?page=dashboard">
<input type="hidden" name="do" value="sendtest"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<label>To</label>
<input type="text" name="email" placeholder="you@example.com">
<label>From domain</label>
<select name="domain">
<?php foreach ($appData["domains"] as $domainEntry) echo "<option value='" . h($domainEntry["domain"] ?? "") . "'>" . h($domainEntry["domain"] ?? "") . "</option>"; ?>
</select>
<button type="submit">Send Test</button>
</form>
</div>
<?php
}

// ---------------------------------------------------------------------------------

else if ($page == "domains") {

$editID = strtolower(trim((string)($_GET["edit"] ?? "")));
$editEntry = (strlen($editID) > 0) ? GetDomainEntry($editID, $appData) : false;
if (!is_array($editEntry)) $editEntry = array();

$providers = array("sendgrid", "mailgun", "postmark", "ses", "smtp2go", "brevo", "sparkpost", "elasticemail", "mailtrap", "mailjet", "smtpcom", "other");
?>
<div class="card">
<h2>Sending Domains</h2>
<?php if (count($appData["domains"]) < 1) echo "<p>No domains yet. Add your first one below.</p>"; else { ?>
<table><tr><th>Domain</th><th>Sender</th><th>Provider</th><th>SMTP host</th><th></th></tr>
<?php foreach ($appData["domains"] as $domainEntry) { ?>
<tr>
<td class="mono"><?php echo h($domainEntry["domain"] ?? ""); ?></td>
<td class="mono"><?php echo h(($domainEntry["alias"] ?? "") . "@" . ($domainEntry["domain"] ?? "")); ?></td>
<td><?php echo h($domainEntry["provider"] ?? ""); ?></td>
<td class="mono"><?php echo h($domainEntry["smtpHost"] ?? ""); ?></td>
<td>
<a href="admin.php?page=domains&amp;edit=<?php echo urlencode($domainEntry["domain"] ?? ""); ?>">Edit</a>
<form class="inlineform" method="post" action="admin.php?page=domains" onsubmit="return confirm('Delete this domain?');">
<input type="hidden" name="do" value="deletedomain"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<input type="hidden" name="domain" value="<?php echo h($domainEntry["domain"] ?? ""); ?>">
<button type="submit" class="danger">Delete</button></form>
</td>
</tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="card">
<h2><?php echo (count($editEntry) > 0) ? "Edit Domain: " . h($editID) : "Add a Sending Domain"; ?></h2>
<form method="post" action="admin.php?page=domains">
<input type="hidden" name="do" value="savedomain"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

<label>Domain</label>
<input type="text" name="domain" value="<?php echo h($editID); ?>" <?php echo (count($editEntry) > 0) ? "readonly" : ""; ?> placeholder="mail.yourbrand.com">

<label>Sender alias (the part before the @, so alias@domain is the from address)</label>
<input type="text" name="alias" value="<?php echo h($editEntry["alias"] ?? ""); ?>" placeholder="news">

<label>Sender name</label>
<input type="text" name="senderName" value="<?php echo h($editEntry["senderName"] ?? ""); ?>" placeholder="Your Brand">

<label>Provider (picks the webhook parser, see the README for SMTP values)</label>
<select name="provider">
<?php foreach ($providers as $providerOption) {
	$selected = (($editEntry["provider"] ?? "") == $providerOption) ? " selected" : "";
	echo "<option value='" . $providerOption . "'" . $selected . ">" . $providerOption . "</option>";
} ?>
</select>

<label>SMTP host</label>
<input type="text" name="smtpHost" value="<?php echo h($editEntry["smtpHost"] ?? ""); ?>" placeholder="smtp.sendgrid.net">

<label>SMTP port (465 for SSL, 587 for STARTTLS)</label>
<input type="number" name="smtpPort" value="<?php echo (int)($editEntry["smtpPort"] ?? 587); ?>">

<label>SMTP username</label>
<input type="text" name="smtpUser" value="<?php echo h($editEntry["smtpUser"] ?? ""); ?>" class="mono">

<label>SMTP password (leave blank to keep the saved one)</label>
<input type="password" name="smtpPass" value="">

<label>Chatbot ID (optional, used by api.php/chatbot/respond for this domain)</label>
<input type="text" name="chatbotID" value="<?php echo h($editEntry["chatbotID"] ?? ""); ?>">

<button type="submit">Save Domain</button>
</form>
</div>
<?php
}

// ---------------------------------------------------------------------------------

else if ($page == "messages") {

$editID = (string)($_GET["edit"] ?? "");
$editMsg = (strlen($editID) > 0) ? ($appData["messages"][$editID] ?? array()) : array();

$arstatus = explode(",", $editMsg["arstatus"] ?? "0,0,0,0,0,0,0");
?>
<div class="card">
<h2>Messages</h2>
<?php if (count($appData["messages"]) < 1) echo "<p>No messages yet. Create your first one below.</p>"; else { ?>
<table><tr><th>ID</th><th>Name</th><th>Subject</th><th>Domain</th><th>Auto days</th><th></th></tr>
<?php foreach ($appData["messages"] as $mid => $msg) {
	$days = "";
	$arList = explode(",", $msg["arstatus"] ?? "");
	foreach ($arList as $d => $on) { if ($on == "1") $days .= $dayNames[$d] . " "; }
?>
<tr>
<td class="mono"><?php echo h($mid); ?></td>
<td><?php echo h($msg["name"] ?? ""); ?></td>
<td><?php echo h(substr($msg["subject"] ?? "", 0, 40)); ?></td>
<td class="mono"><?php echo h($msg["domain"] ?? ""); ?></td>
<td class="small"><?php echo h(strlen($days) > 0 ? $days : "off"); ?></td>
<td>
<a href="admin.php?page=messages&amp;edit=<?php echo urlencode($mid); ?>">Edit</a>
<form class="inlineform" method="post" action="admin.php?page=messages" onsubmit="return confirm('Delete this message?');">
<input type="hidden" name="do" value="deletemessage"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<input type="hidden" name="messageID" value="<?php echo h($mid); ?>">
<button type="submit" class="danger">Delete</button></form>
</td>
</tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="card">
<h2><?php echo (count($editMsg) > 0) ? "Edit Message: " . h($editID) : "Create a Message"; ?></h2>
<form method="post" action="admin.php?page=messages">
<input type="hidden" name="do" value="savemessage"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<input type="hidden" name="messageID" value="<?php echo h($editID); ?>">

<label>Name</label>
<input type="text" name="name" value="<?php echo h($editMsg["name"] ?? ""); ?>" placeholder="spring-offer">

<label>Subject (##FNAME## works here too)</label>
<input type="text" name="subject" value="<?php echo h($editMsg["subject"] ?? ""); ?>">

<label>HTML body (placeholders: ##FNAME##, ##DOMAIN##, ##SUBID##, ##UNSUB##)</label>
<textarea name="html" rows="10" placeholder="&lt;p&gt;Hi ##FNAME##,&lt;/p&gt; ... &lt;a href='https://yourdomain.com/click.php/##SUBID##'&gt;See it&lt;/a&gt; ... &lt;a href='##UNSUB##'&gt;Unsubscribe&lt;/a&gt;"><?php echo h($editMsg["html"] ?? ""); ?></textarea>

<label>Plain text body (blank auto-generates from the HTML)</label>
<textarea name="text" rows="4"><?php echo h($editMsg["text"] ?? ""); ?></textarea>

<label>Redirect URL (where tracked clicks land, ##SUBID## is filled in)</label>
<input type="text" name="redirectUrl" value="<?php echo h($editMsg["redirectUrl"] ?? ""); ?>" placeholder="https://youroffer.com/?subid=##SUBID##">

<label>Sending domain (used by the auto scheduler)</label>
<select name="domain">
<option value="">none</option>
<?php foreach ($appData["domains"] as $domainEntry) {
	$selected = (($editMsg["domain"] ?? "") == ($domainEntry["domain"] ?? "")) ? " selected" : "";
	echo "<option value='" . h($domainEntry["domain"] ?? "") . "'" . $selected . ">" . h($domainEntry["domain"] ?? "") . "</option>";
} ?>
</select>

<label>Allowed states (comma list like FL,TX, blank for all)</label>
<input type="text" name="allowedStates" value="<?php echo h($editMsg["allowedStates"] ?? ""); ?>">

<label>Auto schedule days</label>
<div style="margin: 4px 0 12px;">
<?php for ($d = 0; $d < 7; $d++) { ?>
<label style="font-weight:normal;margin-right:10px;"><input type="checkbox" name="ar<?php echo $d; ?>" value="1" <?php echo (($arstatus[$d] ?? "0") == "1") ? "checked" : ""; ?> style="width:auto;margin:0 4px 0 0;"><?php echo $dayNames[$d]; ?></label>
<?php } ?>
</div>

<button type="submit">Save Message</button>
</form>
</div>
<?php
}

// ---------------------------------------------------------------------------------

else if ($page == "lists") {

$editID = (string)($_GET["edit"] ?? "");
$editList = (strlen($editID) > 0) ? ($appData["lists"][$editID] ?? array()) : array();
?>
<div class="card">
<h2>Lists</h2>
<p class="small">A list is a sending profile: which domain sends, how many go out, and feed filters. The auto scheduler builds one broadcast per list per day.</p>
<?php if (count($appData["lists"]) < 1) echo "<p>No lists yet. Create your first one below.</p>"; else { ?>
<table><tr><th>ID</th><th>Name</th><th>Domain</th><th>Volume</th><th></th></tr>
<?php foreach ($appData["lists"] as $listID => $listData) { ?>
<tr>
<td class="mono"><?php echo h($listID); ?></td>
<td><?php echo h($listData["name"] ?? ""); ?></td>
<td class="mono"><?php echo h($listData["domain"] ?? ""); ?></td>
<td><?php echo (int)($listData["totalVolume"] ?? 0); ?></td>
<td>
<a href="admin.php?page=lists&amp;edit=<?php echo urlencode($listID); ?>">Edit</a>
<form class="inlineform" method="post" action="admin.php?page=lists" onsubmit="return confirm('Delete this list?');">
<input type="hidden" name="do" value="deletelist"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<input type="hidden" name="listID" value="<?php echo h($listID); ?>">
<button type="submit" class="danger">Delete</button></form>
</td>
</tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="card">
<h2><?php echo (count($editList) > 0) ? "Edit List: " . h($editID) : "Create a List"; ?></h2>
<form method="post" action="admin.php?page=lists">
<input type="hidden" name="do" value="savelist"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

<label>List ID (letters, numbers, dashes, underscores)</label>
<input type="text" name="listID" value="<?php echo h($editID); ?>" <?php echo (count($editList) > 0) ? "readonly" : ""; ?> placeholder="main-list">

<label>Name</label>
<input type="text" name="name" value="<?php echo h($editList["name"] ?? ""); ?>">

<label>Sending domain</label>
<select name="domain">
<?php foreach ($appData["domains"] as $domainEntry) {
	$selected = (($editList["domain"] ?? "") == ($domainEntry["domain"] ?? "")) ? " selected" : "";
	echo "<option value='" . h($domainEntry["domain"] ?? "") . "'" . $selected . ">" . h($domainEntry["domain"] ?? "") . "</option>";
} ?>
</select>

<label>Daily volume (used by the auto scheduler, up to 99999)</label>
<input type="number" name="totalVolume" min="0" max="99999" value="<?php echo (int)($editList["totalVolume"] ?? 0); ?>">

<label>Allowed feeds (comma list, blank sends to every feed)</label>
<input type="text" name="allowedFeeds" value="<?php echo h($editList["allowedFeeds"] ?? ""); ?>">

<button type="submit">Save List</button>
</form>
</div>
<?php
}

// ---------------------------------------------------------------------------------

else if ($page == "series") {

$editID = (string)($_GET["edit"] ?? "");
$editSeries = (strlen($editID) > 0) ? ($appData["series"][$editID] ?? array()) : array();
?>
<div class="card">
<h2>Responder Series</h2>
<p class="small">A series is a drip sequence: subscribe an address once and the messages go out on the chosen rhythm until the list runs out or the person unsubscribes.</p>
<?php if (count($appData["series"]) < 1) echo "<p>No series yet. Create your first one below.</p>"; else { ?>
<table><tr><th>ID</th><th>Domain</th><th>Frequency</th><th>Messages</th><th></th></tr>
<?php foreach ($appData["series"] as $seriesID => $seriesData) { ?>
<tr>
<td class="mono"><?php echo h($seriesID); ?></td>
<td class="mono"><?php echo h($seriesData["domain"] ?? ""); ?></td>
<td><?php echo h($seriesData["freq"] ?? ""); ?></td>
<td class="mono"><?php echo h(implode(",", $seriesData["messageList"] ?? array())); ?></td>
<td>
<a href="admin.php?page=series&amp;edit=<?php echo urlencode($seriesID); ?>">Edit</a>
<form class="inlineform" method="post" action="admin.php?page=series" onsubmit="return confirm('Delete this series?');">
<input type="hidden" name="do" value="deleteseries"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<input type="hidden" name="seriesID" value="<?php echo h($seriesID); ?>">
<button type="submit" class="danger">Delete</button></form>
</td>
</tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="card">
<h2><?php echo (count($editSeries) > 0) ? "Edit Series: " . h($editID) : "Create a Series"; ?></h2>
<form method="post" action="admin.php?page=series">
<input type="hidden" name="do" value="saveseries"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

<label>Series ID (letters, numbers, dashes, underscores)</label>
<input type="text" name="seriesID" value="<?php echo h($editID); ?>" <?php echo (count($editSeries) > 0) ? "readonly" : ""; ?> placeholder="welcome">

<label>Sending domain</label>
<select name="domain">
<?php foreach ($appData["domains"] as $domainEntry) {
	$selected = (($editSeries["domain"] ?? "") == ($domainEntry["domain"] ?? "")) ? " selected" : "";
	echo "<option value='" . h($domainEntry["domain"] ?? "") . "'" . $selected . ">" . h($domainEntry["domain"] ?? "") . "</option>";
} ?>
</select>

<label>Frequency</label>
<select name="freq">
<?php foreach (array("daily", "weekly", "monthly") as $freqOption) {
	$selected = (($editSeries["freq"] ?? "daily") == $freqOption) ? " selected" : "";
	echo "<option value='" . $freqOption . "'" . $selected . ">" . $freqOption . "</option>";
} ?>
</select>

<label>Message list (comma list of message IDs, in send order)</label>
<input type="text" name="messageList" value="<?php echo h(implode(",", $editSeries["messageList"] ?? array())); ?>" placeholder="1,2,3">

<label>Skip hours (comma list 0 to 23, blank for none)</label>
<input type="text" name="exceptHours" value="<?php echo h(implode(",", $editSeries["exceptHours"] ?? array())); ?>" placeholder="0,1,2,3,4,5,6,22,23">

<label>Skip days (comma list, 0 Sunday to 6 Saturday, blank for none)</label>
<input type="text" name="exceptDays" value="<?php echo h(implode(",", $editSeries["exceptDays"] ?? array())); ?>" placeholder="0,6">

<button type="submit">Save Series</button>
</form>
</div>
<?php
}

// ---------------------------------------------------------------------------------

else if ($page == "contacts") {

$findEmail = CleanEmail($_GET["find"] ?? "");
$foundContact = (strlen($findEmail) > 4) ? GetContact($findEmail) : false;
$foundSuppressed = (strlen($findEmail) > 4) ? GetSuppressed($findEmail) : false;
?>
<div class="card">
<h2>Contacts</h2>
<div class="statgrid">
<div class="stat"><b><?php echo CountContacts(); ?></b><span>active contacts</span></div>
<div class="stat"><b><?php echo CountSuppressed(); ?></b><span>suppressed</span></div>
</div>
<form method="get" action="admin.php" style="max-width:320px;">
<input type="hidden" name="page" value="contacts">
<label>Look up an address</label>
<input type="text" name="find" value="<?php echo h($findEmail); ?>" placeholder="someone@example.com">
<button type="submit">Find</button>
</form>
<?php if (strlen($findEmail) > 4) {
if ($foundContact) { ?>
<h2>Profile: <?php echo h($findEmail); ?></h2>
<table>
<?php foreach ($foundContact as $key => $value) { if (is_array($value)) $value = json_encode($value); ?>
<tr><td class="mono"><?php echo h($key); ?></td><td class="mono"><?php echo h($value); ?></td></tr>
<?php } ?>
</table>
<form class="inlineform" method="post" action="admin.php?page=contacts" onsubmit="return confirm('Unsubscribe this address from everything?');">
<input type="hidden" name="do" value="unsubcontact"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<input type="hidden" name="email" value="<?php echo h($findEmail); ?>">
<button type="submit" class="danger">Unsubscribe</button></form>
<?php } else if ($foundSuppressed) echo "<p>" . h($findEmail) . " is on the suppressed list.</p>";
else echo "<p>" . h($findEmail) . " was not found.</p>";
} ?>
</div>

<div class="card">
<h2>Upload Contacts</h2>
<p class="small">One record per line: email,fname,lname,feed,sourceurl,optin,ip,state,country. Only email is required, keep the commas for skipped fields. Unsubscribed addresses are never re-imported.</p>
<form method="post" action="admin.php?page=contacts">
<input type="hidden" name="do" value="uploadcontacts"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<textarea name="content" rows="8" placeholder="amy@example.com,Amy,,Default_Feed,,,,FL,US"></textarea>
<button type="submit">Upload</button>
</form>
</div>

<div class="card">
<h2>Upload Responder Subscriptions</h2>
<p class="small">Same line format as contacts. Every address gets subscribed to the chosen series.</p>
<form method="post" action="admin.php?page=contacts">
<input type="hidden" name="do" value="uploadresponders"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<label>Series</label>
<select name="series">
<?php foreach ($appData["series"] as $seriesID => $seriesData) echo "<option value='" . h($seriesID) . "'>" . h($seriesID) . "</option>"; ?>
</select>
<textarea name="content" rows="6" placeholder="amy@example.com,Amy,,Default_Feed"></textarea>
<button type="submit">Upload</button>
</form>
</div>

<div class="card">
<h2>Upload Unsubscribes</h2>
<p class="small">One address per line. Each one is removed from broadcasts and every responder series.</p>
<form method="post" action="admin.php?page=contacts">
<input type="hidden" name="do" value="uploadunsubs"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<textarea name="content" rows="6" placeholder="amy@example.com"></textarea>
<button type="submit">Upload</button>
</form>
</div>
<?php
}

// ---------------------------------------------------------------------------------

else if ($page == "settings") {

$settings = $appData["settings"];
?>
<div class="card">
<h2>Settings</h2>
<form method="post" action="admin.php?page=settings">
<input type="hidden" name="do" value="savesettings"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

<label>Auto scheduler</label>
<select name="scheduleStatus">
<option value="0" <?php echo (($settings["scheduleStatus"] ?? "0") == "0") ? "selected" : ""; ?>>Off, schedule broadcasts by hand</option>
<option value="1" <?php echo (($settings["scheduleStatus"] ?? "0") == "1") ? "selected" : ""; ?>>On, build broadcasts daily from lists and enabled messages</option>
</select>

<label>Auto scheduler hour (0 to 23, runs on the first cron pass at or after this hour)</label>
<input type="number" name="autoScheduleHour" min="0" max="23" value="<?php echo (int)($settings["autoScheduleHour"] ?? 7); ?>">

<label>Pruning count (remove contacts with no clicks after this many sends, 0 disables pruning)</label>
<input type="number" name="pruningCount" min="0" value="<?php echo (int)($settings["pruningCount"] ?? 0); ?>">

<label>Days since click (clickers with no click in this many days get pruned too)</label>
<input type="number" name="daysSinceClick" min="1" value="<?php echo (int)($settings["daysSinceClick"] ?? 30); ?>">

<label>LLM click bot detection (needs botDetectProvider set in config.php)</label>
<select name="clickBotDetect">
<option value="0" <?php echo (($settings["clickBotDetect"] ?? "0") == "0") ? "selected" : ""; ?>>Off, cheap user agent filter only</option>
<option value="1" <?php echo (($settings["clickBotDetect"] ?? "0") == "1") ? "selected" : ""; ?>>On, also ask the LLM about each click</option>
</select>

<button type="submit">Save Settings</button>
</form>
</div>
<?php
}

PageBottom();

?>
