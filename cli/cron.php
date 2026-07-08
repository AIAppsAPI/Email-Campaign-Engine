<?php

require_once __DIR__ . "/../lib/email.php";

// Cron entry point. Run this from crontab, safe as often as every few
// minutes, hourly at minimum for broadcasts to go out on time:
//   */10 * * * * php /path/to/Email-Campaign-Engine/cli/cron.php
// The Docker compose file runs it automatically in a loop.
//
// What it does each run:
// 1. Sends autoresponder messages that are due (each entry sends once).
// 2. Sends the current hour's bucket of every broadcast scheduled for today
//    (each hour bucket sends once).
// 3. Once per day, when the auto scheduler is enabled in the admin settings,
//    builds today's broadcasts from the lists and the messages enabled today.

if (php_sapi_name() != "cli") { http_response_code(403); exit("cli only"); }

$queueDir = GetQueueDir();
$appData = GetAppData();

echo date("Y-m-d H:i:s") . " cron start\n";

// 1. autoresponders
$sent = SendEmailResponderMessages();
echo "responders: " . (int)$sent . " message(s) sent\n";

// 2. today's broadcast hour buckets
$bidData = GetReport("broadcasts" . date("m-d-Y"));
if (($bidData) && (!empty($bidData["userdata"]))) {
foreach ($bidData["userdata"] as $bid => $bidcontent)
{
	$total = SendEmailBroadcastMessages(array("bid" => $bid));
	if ($total === false) continue;
	if ($total > 0) echo "broadcast " . $bid . ": " . $total . " message(s) sent for hour " . date("H") . "\n";
}
} else echo "broadcasts: none scheduled today\n";

// 3. daily auto scheduling
$scheduleStatus = $appData["settings"]["scheduleStatus"] ?? "0";
$autoScheduleHour = (int)($appData["settings"]["autoScheduleHour"] ?? 7);
$lastAutoSchedule = trim((string)@file_get_contents($queueDir . "/lastAutoSchedule.txt"));

if (($scheduleStatus == "1") && ((int)date("G") >= $autoScheduleHour) && ($lastAutoSchedule != date("m-d-Y"))) {
	@file_put_contents($queueDir . "/lastAutoSchedule.txt", date("m-d-Y"));
	$result = AutoScheduleEmail();
	echo "autoschedule: " . ($result["message"] ?? "done") . "\n";
}

echo date("Y-m-d H:i:s") . " cron done\n";

?>
