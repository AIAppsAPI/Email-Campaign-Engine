# Email Campaign Engine

A self-hosted email campaign engine: bulk broadcasts, drip autoresponder series, delivery and engagement webhooks, click tracking, and unsubscribe management, working with 11 email providers over plain SMTP. One small PHP app and one SQLite file, no framework, no Composer, no dependencies.

Email Campaign Engine is the open source release of Email Broadcast, the same engine that runs as a hosted app on aiappsapi.com. If you would rather send without running your own server, the [Email Broadcast app](https://www.aiappsapi.com/apps/emailbroadcast.php) is ready to go, and the [Email Broadcast docs](https://www.aiappsapi.com/docs/emailbroadcast.php) cover the hosted version in depth.

## What you get

- **Broadcasts**: schedule a message to your whole list or a filtered slice. Sends are spread across the business day, with feed and state filters and priority for engaged contacts.
- **Autoresponder series**: drip sequences on a daily, weekly, or monthly rhythm. Subscribe an address once through the API or an upload, and the engine sends each step when it is due, respecting quiet hours and skip days.
- **Auto scheduler**: turn it on and the engine builds the day's broadcasts by itself, one per list, rotating through the messages you enabled for that weekday.
- **Contact profiles**: every address has a profile with ISP group, name, feed, state, send count, message history, and activity level (inactive, clicker, converter). Engaged contacts get priority when a broadcast fills its queue.
- **Unsubscribe management**: a hosted one-click unsubscribe page, the List-Unsubscribe header, webhook unsubscribes, API calls, and uploads all land in a suppression list that survives re-imports. Hard bounces and spam complaints suppress automatically. Optional pruning clears out contacts that never engage.
- **Delivery webhooks**: per provider parsers record delivered, bounces (soft and hard), opens, clicks, complaints, and unsubscribes as reports come in.
- **Click tracking**: tracked links redirect through the engine, count clicks, upgrade the contact to clicker, and filter bots (with an optional LLM check).
- **Built-in SMTP client**: a small dependency-free SMTP sender with STARTTLS and SSL, so the same code works with every provider below or any standard relay.
- **Chatbot hook**: forward a reply through the API and a chat bot (an [AbraTabia AI Storyteller](https://github.com/AIAppsAPI/AbraTabia-AI-Storyteller) install, for example) answers it by email.
- **Admin area**: manage domains, messages, lists, series, contacts, and reports from the browser, including a one-click SMTP test.

Supported providers: SendGrid, Mailgun, Postmark, Amazon SES, SMTP2GO, Brevo, SparkPost, Elastic Email, Mailtrap, Mailjet, SMTP.com. Anything else with an SMTP relay works for sending, you just will not get its webhook events.

## Quick start (Docker)

```
cp config.sample.php config.php
# edit config.php: set apiKey, adminPassword, webhookKey, publicUrl
docker compose up --build
```

The app is at `http://localhost:8080/` with the admin at `admin.php`. The compose file includes a cron container, so scheduled sending works out of the box.

## Quick start (local or shared hosting)

Requires PHP 8.1+ with the curl, openssl, and pdo_sqlite extensions (all standard).

```
cp config.sample.php config.php
# edit config.php as above
php -S localhost:8080 -t public
```

On shared hosting, upload the project and point your docroot (or a subdomain) at the `public/` folder. The SQLite database is created automatically in `data/`. Then add the cron job below.

## Cron

Everything scheduled goes out through `cli/cron.php`. Add one crontab line:

```
*/10 * * * * php /path/to/Email-Campaign-Engine/cli/cron.php
```

Every 10 minutes keeps responders on time, hourly also works. The script is safe to run as often as you like: responder steps send once, each broadcast hour bucket sends once, and the auto scheduler runs once per day. Docker users can skip this, the compose cron container already loops it.

Each cron run does three things:

1. Sends autoresponder messages that are due.
2. Sends the current hour's bucket of today's broadcasts.
3. Once per day (when the auto scheduler is on in the admin settings), builds today's broadcasts from your lists.

## Project structure

```
config.sample.php   copy to config.php, holds keys and settings
public/
  api.php           the JSON API
  webhook.php       provider callbacks for delivery and engagement events
  click.php         tracked click redirect
  unsub.php         hosted one-click unsubscribe page
  admin.php         admin area
lib/
  email.php         campaign engine and the 11 provider webhook parsers
  smtp.php          dependency-free SMTP client (SSL and STARTTLS)
  db.php            SQLite storage layer
cli/
  cron.php          scheduled sending entry point
data/               SQLite database and queue files live here (gitignored)
```

## Setup walkthrough

1. In `config.php` set `apiKey`, `adminPassword`, `webhookKey`, and `publicUrl`.
2. Log in to `admin.php` and add a **sending domain** with your provider's SMTP settings (table below). The from address becomes `alias@domain`.
3. Send yourself a **test email** from the dashboard to confirm SMTP works.
4. Point your provider's event webhooks at `https://yourdomain.com/webhook.php/{provider}?key=YOURWEBHOOKKEY`.
5. Create a **message**. Placeholders: `##FNAME##` first name, `##SUBID##` click tracking id, `##DOMAIN##` the sending domain, `##UNSUB##` the hosted unsubscribe link. A tracked link looks like `https://yourdomain.com/click.php/##SUBID##`.
6. Upload **contacts** in the admin area or push them through the API.
7. Create a **list** (sending profile) and schedule a broadcast from the dashboard, or enable the auto scheduler in settings and check the message's weekday boxes.
8. For drips, create a **series** and subscribe addresses through the API, an upload, or your signup form.

## Provider SMTP settings

| Provider | SMTP host | Username | Password |
| --- | --- | --- | --- |
| SendGrid | smtp.sendgrid.net | apikey | your SendGrid API key |
| Mailgun | smtp.mailgun.org | postmaster@your-domain | Mailgun SMTP password |
| Postmark | smtp.postmarkapp.com | server token | server token |
| Amazon SES | email-smtp.us-east-1.amazonaws.com (region specific) | SES SMTP username | SES SMTP password |
| SMTP2GO | mail.smtp2go.com | SMTP2GO username | SMTP2GO password |
| Brevo | smtp-relay.brevo.com | account email | Brevo SMTP key |
| SparkPost | smtp.sparkpostmail.com | SMTP_Injection | SparkPost API key |
| Elastic Email | smtp.elasticemail.com | account email | Elastic Email API key |
| Mailtrap | live.smtp.mailtrap.io | api | Mailtrap sending token |
| Mailjet | in-v3.mailjet.com | Mailjet API key | Mailjet secret key |
| SMTP.com | send.smtp.com | account username | SMTP.com password |

Use port 465 for SSL or 587 for STARTTLS, both work.

## API

All endpoints are POST with a JSON body. Send your key in the `X-API-Key` header (or as an `apiKey` field in the body). Paths use path info, and if your server does not support that, `api.php?action=send` works the same as `api.php/send`.

### POST api.php/send

Send one email right now.

```
curl -X POST https://yourdomain.com/api.php/send \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"email": "amy@example.com", "domain": "mail.yourbrand.com", "subject": "Hello", "html": "<p>Hello from the engine</p>"}'
```

### POST api.php/contacts/add

Add one contact. Fields: `email` (required), `fname`, `lname`, `feed`, `state`, `country`, `sourceurl`, `optin`, `ip`. Wire this to your signup form.

### POST api.php/contacts/upload

Bulk import. Field: `content`, one record per line as `email,fname,lname,feed,sourceurl,optin,ip,state,country`. Only email is required, keep the commas for skipped fields.

### POST api.php/responder/create

Subscribe an address to a drip series. Fields: `email`, `series` (required), `starttime` (timestamp), `fname`, `feed`, `excludeISPs`. During business hours message 0 goes out immediately.

### POST api.php/responder/unsub

Remove an address from one series (`email`, `series`) or from all series (`email` only).

### POST api.php/responders/upload

Bulk subscribe. Fields: `content` (contact line format), `series`.

### POST api.php/unsub

Unsubscribe an address from everything: broadcasts and all series. Field: `email`.

### POST api.php/unsubs/upload

Bulk unsubscribe. Field: `content`, one address per line.

### POST api.php/broadcast/schedule

Queue a broadcast for today. Fields: `listID`, `offerid` (the message ID), `totalToSend`, optional `redirectUrl` override. Cron sends each hour bucket as it comes up.

### POST api.php/broadcast/autoschedule

Run the auto scheduler now instead of waiting for the daily cron pass.

### POST api.php/broadcast/dataquery

Rebuild the queue files and activity counts from the contact table. Runs automatically before scheduling when the data is older than a day.

### POST api.php/convert

Mark an address as a converter so it gets top priority in future broadcasts. Field: `email`. Call this from your checkout or lead endpoint.

### POST api.php/chatbot/respond

Forward an incoming reply to the chatbot hook and email the answer back. Fields: `email`, `domain`, `message`, optional `chatbotID` (defaults to the domain's setting). Needs `chatbotApiUrl` in config.php.

### POST api.php/stats

Daily numbers. Optional field: `date` as `m-d-Y`. Returns delivered, bounces, opens, clicks, complaints, unsubs, the activity counts, and list sizes.

## Webhooks

Point each provider's event webhooks at:

```
https://yourdomain.com/webhook.php/{provider}?key=YOURWEBHOOKKEY
```

where `{provider}` is one of: `sendgrid`, `mailgun`, `postmark`, `ses`, `smtp2go`, `brevo`, `sparkpost`, `elasticemail`, `mailtrap`, `mailjet`, `smtpcom`. For SES, subscribe the URL to your SNS topic, the engine confirms the subscription automatically. Hard bounces, spam complaints, and unsubscribes suppress the address everywhere.

## Unsubscribes and click tracking

- Put `##UNSUB##` in your HTML as the unsubscribe link. It becomes a hosted one-click unsubscribe page, and the same URL rides in the List-Unsubscribe header so mail clients show their native unsubscribe button.
- Put `https://yourdomain.com/click.php/##SUBID##` around your links. The click redirects to the message's redirect URL with `##SUBID##` substituted, so your landing page receives the tracking id. Known bots are sent to `defaultRedirect` and not counted, and `botDetectProvider` in config.php adds an optional LLM check.

## Compliance notes

- Only mail people who opted in, and honor opt-outs immediately. The suppression list, the hosted unsubscribe page, and the List-Unsubscribe header are there to keep you clean, use them.
- CAN-SPAM and similar laws require a working unsubscribe link and your postal address in every commercial message, put both in your templates.
- Set up SPF, DKIM, and DMARC on every sending domain with your provider before sending volume, or your mail will not reach the inbox.
- Set `apiKey`, `adminPassword`, and `webhookKey` to long random strings and serve over HTTPS before going live.

## Author

Paul Crinigan, [https://www.aiappsapi.com/](https://www.aiappsapi.com/)

## More from the author

- [SMS Campaign Engine](https://github.com/AIAppsAPI/SMS-Campaign-Engine), the SMS twin of this project: broadcasts, drips, and delivery webhooks across 12 SMS providers.
- [AbraTabia AI Storyteller](https://github.com/AIAppsAPI/AbraTabia-AI-Storyteller), a self-hosted AI storyteller and game host API, and a drop-in match for this engine's chatbot hook.
- [Auto Learning Agents](https://www.autolearningagents.com/get-started/), AI agents that learn your business and get better every day.
- [Adaptive Recall](https://www.adaptiverecall.com/features.php), a persistent memory system for AI assistants.
- [ACP Payments Module](https://www.afcommerce.com/free-acp-payments-module/), a free agentic commerce payments module.

## License

MIT, see LICENSE.
