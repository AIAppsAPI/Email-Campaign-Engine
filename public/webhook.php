<?php

require_once __DIR__ . "/../lib/email.php";

// Provider webhook receiver for delivery events, opens, clicks, bounces,
// complaints, and unsubscribes. Point each provider at:
//   https://yourdomain.com/webhook.php/{provider}?key=WEBHOOKKEY
// Providers: sendgrid, mailgun, postmark, ses, smtp2go, brevo, sparkpost,
// elasticemail, mailtrap, mailjet, smtpcom.
// The key must match webhookKey in config.php.

$config = GetConfig();

$webhookKey = (string)($config["webhookKey"] ?? "");
$sentKey = (string)($_GET["key"] ?? ($_SERVER["HTTP_X_WEBHOOK_KEY"] ?? ""));
if ((strlen($webhookKey) < 12) || (!hash_equals($webhookKey, $sentKey))) {
	http_response_code(401);
	echo "invalid webhook key";
	exit;
}

$provider = strtolower(trim((string)($_SERVER["PATH_INFO"] ?? ($_GET["provider"] ?? "")), "/"));

// Providers post JSON or form fields depending on the event, accept both.
$rawBody = file_get_contents("php://input");
$jsonData = json_decode($rawBody, true);
if (!is_array($jsonData)) $jsonData = array_merge($_GET, $_POST);
unset($jsonData["key"], $jsonData["provider"]);

// Amazon SNS subscription confirmations arrive before SES events flow.
// Confirm automatically so setup is one click.
if (($provider == "ses") && (($jsonData["Type"] ?? "") == "SubscriptionConfirmation") && (strlen($jsonData["SubscribeURL"] ?? "") > 10)) {
	$ch = curl_init($jsonData["SubscribeURL"]);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15));
	curl_exec($ch);
	curl_close($ch);
	echo "subscribed";
	exit;
}

switch ($provider)
{
	case "sendgrid":
		EmailSendGridWebhook($jsonData);
	break;
	case "mailgun":
		EmailMailgunWebhook($jsonData);
	break;
	case "postmark":
		EmailPostmarkWebhook($jsonData);
	break;
	case "ses":
		EmailSESWebhook($jsonData);
	break;
	case "smtp2go":
		EmailSMTP2GOWebhook($jsonData);
	break;
	case "brevo":
		EmailBrevoWebhook($jsonData);
	break;
	case "sparkpost":
		EmailSparkPostWebhook($jsonData);
	break;
	case "elasticemail":
		EmailElasticEmailWebhook($jsonData);
	break;
	case "mailtrap":
		EmailMailtrapWebhook($jsonData);
	break;
	case "mailjet":
		EmailMailjetWebhook($jsonData);
	break;
	case "smtpcom":
		EmailSMTPComWebhook($jsonData);
	break;
	default:
		http_response_code(404);
		echo "unknown provider";
		exit;
}

echo "ok";

?>
