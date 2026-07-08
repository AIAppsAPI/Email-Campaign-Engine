<?php

require_once __DIR__ . "/../lib/email.php";

// JSON API entry point. Routes use path info, for example:
//   POST api.php/send
//   POST api.php/contacts/add
//   POST api.php/contacts/upload
//   POST api.php/responder/create
//   POST api.php/responder/unsub
//   POST api.php/responders/upload
//   POST api.php/unsub
//   POST api.php/unsubs/upload
//   POST api.php/broadcast/schedule
//   POST api.php/broadcast/autoschedule
//   POST api.php/broadcast/dataquery
//   POST api.php/convert
//   POST api.php/chatbot/respond
//   POST api.php/stats
// If your server does not pass path info, use api.php?action=send instead.
// Every call must send the config apiKey in the X-API-Key header, or as an
// apiKey field in the JSON body.

$config = GetConfig();

$origin = $config["corsOrigin"] ?? "*";
header("Access-Control-Allow-Origin: " . $origin);
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if (($_SERVER["REQUEST_METHOD"] ?? "") == "OPTIONS") exit;

header("Content-Type: application/json");

$rawBody = file_get_contents("php://input");
$jsonData = json_decode($rawBody, true);
if (!is_array($jsonData)) $jsonData = $_POST;
if (!is_array($jsonData)) $jsonData = array();

// Authentication
$apiKey = (string)($config["apiKey"] ?? "");
if (strlen($apiKey) < 12) {
	http_response_code(500);
	echo json_encode(array("error" => "Set apiKey in config.php to a random string of at least 12 characters before using the API."));
	exit;
}

$sentKey = (string)($_SERVER["HTTP_X_API_KEY"] ?? ($jsonData["apiKey"] ?? ""));
if (!hash_equals($apiKey, $sentKey)) {
	http_response_code(401);
	echo json_encode(array("error" => "Invalid or missing API key. Send it in the X-API-Key header."));
	exit;
}

$action = trim((string)($_SERVER["PATH_INFO"] ?? ($_GET["action"] ?? "")), "/");

switch ($action)
{
	case "send":
		$response = SendEmailMessage($jsonData);
	break;
	case "contacts/add":
		$response = PostEmailBroadcastData($jsonData);
	break;
	case "contacts/upload":
		set_time_limit(0);
		$response = UploadEmailData($jsonData);
	break;
	case "responder/create":
		$response = CreateEmailResponderSeries($jsonData);
	break;
	case "responder/unsub":
		$done = RecordEmailResponderUnsub($jsonData);
		$response = $done ? array("message" => "Unsubscribed from the responder series.") : array("error" => "email is invalid.");
	break;
	case "responders/upload":
		set_time_limit(0);
		$response = UploadEmailResponders($jsonData);
	break;
	case "unsub":
		$done = EmailUnsubFromAll($jsonData);
		$response = $done ? array("message" => "Unsubscribed from everything.") : array("error" => "email is invalid.");
	break;
	case "unsubs/upload":
		set_time_limit(0);
		$response = UploadEmailUnsubs($jsonData);
	break;
	case "broadcast/schedule":
		set_time_limit(0);
		$response = ScheduleEmail($jsonData);
	break;
	case "broadcast/autoschedule":
		set_time_limit(0);
		$response = AutoScheduleEmail($jsonData);
	break;
	case "broadcast/dataquery":
		set_time_limit(0);
		$response = array("message" => "Data query finished.", "counts" => BroadcastDataQuery($jsonData));
	break;
	case "convert":
		$response = EmailMarkRecordAsConverter($jsonData);
	break;
	case "chatbot/respond":
		set_time_limit(0);
		$response = ProcessChatbotResponse($jsonData["email"] ?? "", $jsonData["domain"] ?? "", $jsonData["message"] ?? "", $jsonData["chatbotID"] ?? "");
	break;
	case "stats":
		$date = $jsonData["date"] ?? date("m-d-Y");
		$daily = GetDailyReports($date);
		$response = array("date" => $date, "reports" => $daily["reports"], "datacount" => GetReport("datacount" . $date), "contacts" => CountContacts(), "suppressed" => CountSuppressed());
	break;
	default:
		http_response_code(404);
		$response = array("error" => "Unknown action. Valid actions: send, contacts/add, contacts/upload, responder/create, responder/unsub, responders/upload, unsub, unsubs/upload, broadcast/schedule, broadcast/autoschedule, broadcast/dataquery, convert, chatbot/respond, stats.");
	break;
}

if (!isset($response["error"])) $response["error"] = "";
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);

?>
