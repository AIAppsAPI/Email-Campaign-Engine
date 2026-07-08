<?php

require_once __DIR__ . "/../lib/email.php";

// Tracked click redirect. Build your message links as:
//   https://yourdomain.com/click.php/##SUBID##
// The engine fills ##SUBID## with {u}/{b}. Broadcast clicks carry the user
// index and broadcast id in base36, responder clicks carry the messageID and
// series-index. Bots get sent to the default redirect and are not counted.

$pathInfo = trim((string)($_SERVER["PATH_INFO"] ?? ""), "/");
$parts = explode("/", $pathInfo);

$bUserID = $parts[0] ?? ($_GET["u"] ?? "");
$broadcastID = $parts[1] ?? ($_GET["b"] ?? "");
$browser = $_SERVER["HTTP_USER_AGENT"] ?? "";

$config = GetConfig();
$fallback = $config["defaultRedirect"] ?? "";
if (strlen($fallback) < 10) $fallback = "https://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . "/";

if ((strlen($bUserID) < 1) || (strlen($broadcastID) < 1)) {
	header("Location: " . $fallback);
	exit;
}

$redirectUrl = EmailClickRedirect($bUserID, $broadcastID, $browser);
if (strlen($redirectUrl) < 10) $redirectUrl = $fallback;

header("Location: " . $redirectUrl);
exit;

?>
