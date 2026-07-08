<?php

require_once __DIR__ . "/../lib/email.php";

// Public unsubscribe page. Messages link here through the ##UNSUB##
// placeholder, and the List-Unsubscribe header points here too, so one-click
// unsubscribes from mail clients land on this page as POST requests.
//   unsub.php?e=EMAIL          unsubscribe from everything
//   unsub.php?e=EMAIL&s=SERIES unsubscribe from one responder series

$email = CleanEmail(urldecode($_GET["e"] ?? ($_POST["e"] ?? "")));
$series = (string)($_GET["s"] ?? ($_POST["s"] ?? ""));

$done = false;
if (strlen($email) > 4) {
	if (strlen($series) > 0) $done = RecordEmailResponderUnsub(array("email" => $email, "series" => $series));
	else $done = EmailUnsubFromAll(array("email" => $email));

	if ($done) {
		$bidData = GetDailyReports();
		$bidData["reports"]["unsubs"]++;
		SaveDailyReports($bidData);
	}
}

?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unsubscribe</title>
<style>
body { font-family: Arial, Helvetica, sans-serif; background: #f4f5f7; color: #222; margin: 0; }
.card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 24px; max-width: 460px; margin: 60px auto; text-align: center; }
</style></head><body>
<div class="card">
<?php if ($done) { ?>
<h2>You are unsubscribed</h2>
<p>We will not send any more messages to this address.</p>
<?php } else { ?>
<h2>Something went wrong</h2>
<p>We could not process this unsubscribe link. The address may be missing or already removed.</p>
<?php } ?>
</div>
</body></html>
