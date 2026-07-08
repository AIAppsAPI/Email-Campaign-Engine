<?php

// Email Campaign Engine configuration.
// Copy this file to config.php in the same folder and fill in your values.
// config.php is gitignored so your keys never end up in the repo.

return array(

// Client API key. Every /api call must send this in the X-API-Key header
// (or as an apiKey field in the JSON body). Set a long random string.
"apiKey" => "",

// Password for admin.php. At least 8 characters.
"adminPassword" => "",

// Key that email providers must include when calling your webhooks, for
// example webhook.php/sendgrid?key=THISVALUE. Set a long random string.
"webhookKey" => "",

// Browser origins allowed to call the API. "*" allows any site, or set it
// to your app's origin like "https://myapp.example.com".
"corsOrigin" => "*",

// Public base URL of this install, no trailing slash. Used to build the
// unsubscribe links (##UNSUB## and the List-Unsubscribe header).
"publicUrl" => "https://yourdomain.com",

// SQLite database file location.
"dbPath" => __DIR__ . "/data/emailcampaign.sqlite",

// Timezone used for business hours and daily scheduling.
"timezone" => "America/New_York",

// Where clicks go when a tracked link cannot be resolved or a bot is caught.
"defaultRedirect" => "",

// Optional LLM click-bot detection: "off", "anthropic", or "openai".
// Cheap user agent filtering always runs, this adds an LLM check on
// suspicious clicks when the setting is enabled in the admin area.
"botDetectProvider" => "off",
"anthropicApiKey" => "",
"anthropicModel" => "claude-haiku-4-5-20251001",
"openaiApiKey" => "",
"openaiBaseUrl" => "https://api.openai.com/v1",
"openaiModel" => "gpt-4o-mini",

// Optional chatbot hook. Forward a reply through api.php/chatbot/respond
// and the answer comes back by email. Works with an AbraTabia AI
// Storyteller install or anything that speaks the same JSON
// (chatbotID, prompt, convoID).
"chatbotApiUrl" => "",
"chatbotApiKey" => "",

);
