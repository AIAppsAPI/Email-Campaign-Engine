<?php

// Small dependency-free SMTP client. Speaks plain SMTP with AUTH LOGIN or
// AUTH PLAIN, implicit TLS on port 465, and STARTTLS on 587 and 25. Builds a
// multipart/alternative message with text and HTML parts. Covers SendGrid,
// Mailgun, Postmark, Amazon SES, SMTP2GO, Brevo, SparkPost, Elastic Email,
// Mailtrap, Mailjet, SMTP.com, and any other standard SMTP relay.

// ---------------------------------------------------------------------------------

function SMTPReadResponse($socket) {

$response = "";
while (($line = fgets($socket, 2048)) !== false) {
	$response .= $line;
	// multiline responses continue while character 4 is a dash
	if ((strlen($line) < 4) || ($line[3] != "-")) break;
}
return $response;
} // ends function

// ---------------------------------------------------------------------------------

function SMTPCommand($socket, $command, $expectCode) {

if (strlen($command) > 0) fwrite($socket, $command . "\r\n");
$response = SMTPReadResponse($socket);
$code = substr($response, 0, 3);
if ($code != (string)$expectCode) return array("ok" => false, "code" => $code, "response" => trim($response));
return array("ok" => true, "code" => $code, "response" => trim($response));
} // ends function

// ---------------------------------------------------------------------------------

function SMTPEncodeHeaderText($text) {

// keep plain ASCII headers readable, encode anything else
if (preg_match("/^[\x20-\x7E]*$/", $text)) return $text;
return "=?UTF-8?B?" . base64_encode($text) . "?=";
} // ends function

// ---------------------------------------------------------------------------------

// Sends one email. $params needs: smtpHost, smtpUser, smtpPass, smtpPort,
// sender, senderName, email (the recipient), subject, html, text. Optional:
// headers (array of "Name: value" lines, for List-Unsubscribe and friends).
// Returns "" on success or an error string.
function SMTPSendEmail($params) {

$host = $params["smtpHost"] ?? "";
$port = (int)($params["smtpPort"] ?? 587);
$user = $params["smtpUser"] ?? "";
$pass = $params["smtpPass"] ?? "";
$sender = $params["sender"] ?? "";
$senderName = $params["senderName"] ?? "";
$to = $params["email"] ?? "";
$subject = $params["subject"] ?? "";
$html = $params["html"] ?? "";
$text = $params["text"] ?? "";
$extraHeaders = $params["headers"] ?? array();
$timeout = (int)($params["timeout"] ?? 30);

if (strlen($host) < 3) return "invalid smtp host.";
if ((strlen($to) < 5) || (strpos($to, "@") === false)) return "invalid recipient address.";
if ((strlen($sender) < 5) || (strpos($sender, "@") === false)) return "invalid sender address.";
if ($port < 1) $port = 587;

$senderDomain = substr(strrchr($sender, "@"), 1);

// port 465 is implicit TLS, everything else starts plain and upgrades
$target = (($port == 465) ? "ssl://" : "") . $host . ":" . $port;
$context = stream_context_create(array("ssl" => array("verify_peer" => true, "verify_peer_name" => true)));
$socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
if (!$socket) return "connection failed: " . $errstr;
stream_set_timeout($socket, $timeout);

$greeting = SMTPReadResponse($socket);
if (substr($greeting, 0, 3) != "220") { fclose($socket); return "bad greeting: " . trim($greeting); }

$ehlo = SMTPCommand($socket, "EHLO " . $senderDomain, 250);
if (!$ehlo["ok"]) { fclose($socket); return "EHLO failed: " . $ehlo["response"]; }

if ($port != 465) {
	if (substr_count(strtoupper($ehlo["response"]), "STARTTLS") > 0) {
		$tls = SMTPCommand($socket, "STARTTLS", 220);
		if (!$tls["ok"]) { fclose($socket); return "STARTTLS failed: " . $tls["response"]; }
		if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($socket); return "TLS negotiation failed."; }
		$ehlo = SMTPCommand($socket, "EHLO " . $senderDomain, 250);
		if (!$ehlo["ok"]) { fclose($socket); return "EHLO after STARTTLS failed: " . $ehlo["response"]; }
	}
}

if ((strlen($user) > 0) && (strlen($pass) > 0)) {
	$auth = SMTPCommand($socket, "AUTH LOGIN", 334);
	if ($auth["ok"]) {
		$step = SMTPCommand($socket, base64_encode($user), 334);
		if (!$step["ok"]) { fclose($socket); return "AUTH LOGIN username rejected: " . $step["response"]; }
		$step = SMTPCommand($socket, base64_encode($pass), 235);
		if (!$step["ok"]) { fclose($socket); return "authentication failed: " . $step["response"]; }
	} else {
		// fall back to AUTH PLAIN for servers that do not offer LOGIN
		$plain = SMTPCommand($socket, "AUTH PLAIN " . base64_encode("\0" . $user . "\0" . $pass), 235);
		if (!$plain["ok"]) { fclose($socket); return "authentication failed: " . $plain["response"]; }
	}
}

$step = SMTPCommand($socket, "MAIL FROM:<" . $sender . ">", 250);
if (!$step["ok"]) { fclose($socket); return "MAIL FROM rejected: " . $step["response"]; }

$step = SMTPCommand($socket, "RCPT TO:<" . $to . ">", 250);
if (!$step["ok"]) { fclose($socket); return "RCPT TO rejected: " . $step["response"]; }

$step = SMTPCommand($socket, "DATA", 354);
if (!$step["ok"]) { fclose($socket); return "DATA rejected: " . $step["response"]; }

// build the MIME message
if (strlen($text) < 1) $text = trim(strip_tags($html));
$boundary = "b" . md5(uniqid("", true));
$messageID = "<" . uniqid("", true) . "@" . $senderDomain . ">";

$fromHeader = (strlen($senderName) > 0) ? SMTPEncodeHeaderText($senderName) . " <" . $sender . ">" : $sender;

$headers = array();
$headers[] = "Date: " . date("r");
$headers[] = "From: " . $fromHeader;
$headers[] = "To: <" . $to . ">";
$headers[] = "Subject: " . SMTPEncodeHeaderText($subject);
$headers[] = "Message-ID: " . $messageID;
$headers[] = "MIME-Version: 1.0";
foreach ($extraHeaders as $extraHeader) { if (strlen(trim($extraHeader)) > 0) $headers[] = trim($extraHeader); }

$body = "";
if (strlen($html) > 0) {
	$headers[] = "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"";
	$body .= "--" . $boundary . "\r\n";
	$body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
	$body .= quoted_printable_encode($text) . "\r\n\r\n";
	$body .= "--" . $boundary . "\r\n";
	$body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
	$body .= quoted_printable_encode($html) . "\r\n\r\n";
	$body .= "--" . $boundary . "--\r\n";
} else {
	$headers[] = "Content-Type: text/plain; charset=UTF-8";
	$headers[] = "Content-Transfer-Encoding: quoted-printable";
	$body = quoted_printable_encode($text) . "\r\n";
}

$data = implode("\r\n", $headers) . "\r\n\r\n" . $body;

// dot stuffing per RFC 5321
$data = preg_replace("/\r?\n/", "\r\n", $data);
$data = str_replace("\r\n.", "\r\n..", $data);

fwrite($socket, $data . "\r\n.\r\n");
$final = SMTPReadResponse($socket);
$finalCode = substr($final, 0, 3);

SMTPCommand($socket, "QUIT", 221);
fclose($socket);

if ($finalCode != "250") return "message rejected: " . trim($final);
return "";
} // ends function

?>
