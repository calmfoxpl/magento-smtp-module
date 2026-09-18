# Magento SMTP Module

[![Build](https://github.com/calmfoxpl/magento-smtp-module/actions/workflows/build.yml/badge.svg)](https://github.com/calmfoxpl/magento-smtp-module/actions/workflows/build.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

SMTP for Magento 2 with ready-made settings for the popular providers, a log of what was sent, a
periodic check of the connection, and a warning in the panel the day the shop stops being able to
send.

The last part is the reason this module exists. Sending e-mail through a provider is a solved
problem; noticing that it has stopped is not. An API key is revoked, a colleague rotates a
password, a hosting company closes port 587, a provider retires a mechanism — and a Magento shop
carries on taking orders and quietly sending nothing. The usual way a shopkeeper finds out is a
customer asking where their confirmation is, two days later.

## Features

- **Ready-made settings for fifteen providers:** Brevo, Mailgun, SendGrid, Postmark, Amazon SES,
  Mailjet, SMTP2GO, Resend, SparkPost, Mailchimp Transactional, Elastic Email, Zoho, Gmail and
  Google Workspace, Microsoft 365 and Outlook, and a server of your own. Picking one fills in the
  server, the port and the encryption — and, more usefully, says what *that provider* means by
  the two credential fields, which is different at every one of them and guessable at none:
  SendGrid wants the literal word `apikey` as the login, Postmark wants one token in both fields,
  Amazon wants credentials that look exactly like the AWS keys that will never work.
- **A health check that costs nothing:** every quarter of an hour, the module opens a connection,
  raises encryption, logs in and says goodbye — without sending a message. No quota is used, no
  inbox is touched, and the failures that actually happen are caught: an expired key, a rotated
  password, a blocked port, an untrusted certificate, a mechanism a provider has dropped.
- **A warning that reaches somebody.** Four channels, because on a shop whose mail is down one of
  them is broken by definition:
  - a red bar across every admin page, which is the one that always works,
  - an e-mail, sent deliberately through the server's own local mail command rather than the
    provider that is not working,
  - a webhook for Slack, Teams, Discord or a monitor of your own,
  - a line in `var/log` and a non-zero exit code from `bin/magento calmfox:smtp:health`.
- **Quiet when it should be.** A single failed check is a note in the panel, not a phone call:
  mail servers have bad minutes. A refused password is reported at once, because that will not fix
  itself. A problem is reported once, again if its reason changes, once more after a long silence —
  and when it is over, that is reported too.
- **Failures the check cannot see are caught anyway.** Every real message the shop sends is
  watched. Three that fail inside half an hour is a broken shop whatever a connection test says,
  and a message that goes out clears the record, because that is the strongest evidence there is.
- **A log with a resend button.** Sender, recipients, subject, the server's answer — and, for
  failures, the message itself, so the eleven order confirmations that did not go out between nine
  and eleven can be sent once the key is fixed.
- **A report that can be forwarded.** *E-mail → Sending health* says what happened, the one thing
  most worth checking given the provider, and the server's own words — which is what a hosting
  company will ask for.
- **Careful with the credential.** Stored with Magento's own encryptor, never written to the log,
  the alert, the webhook payload or `var/log`; the AUTH lines of a failed conversation are
  redacted before anything is stored.
- **Careful with customer data.** Outgoing mail is customer correspondence. Bodies are not stored
  unless asked for, retention is 30 days by default, and old entries are deleted nightly whether
  the log is switched on or not.
- **Nothing changes until you ask.** With the module off, or configured too incompletely to send,
  Magento's own transport does exactly what it did before. Magento's *Disable Email
  Communications* is honoured above everything, so installing this on a staging copy does not
  start it e-mailing customers.
- **Translations:** English and Polish.

## What it does not do

Worth saying plainly, because a module that overstates this is worse than none:

- **It cannot tell you a message was delivered.** The check proves a connection and a login. Only
  a real message proves delivery, and only the mailbox it lands in can tell you whether it reached
  the inbox or the spam folder. The panel has a button for sending one, and says as much.
- **It does not fix deliverability.** SPF, DKIM and DMARC are records on your domain. This module
  will tell you when the settings are right and the mail still is not arriving, which is the point
  at which those records are the thing to look at.
- **It does not queue.** A message that cannot be sent fails, exactly as it would without the
  module — and is then in the log, where it can be sent again.
- **It does not use provider HTTP APIs.** One SMTP path, which every provider in the list
  supports, and one place for failures to be diagnosed.

## Requirements

| | Version |
|---|---|
| Magento | 2.4.6, 2.4.7 (Open Source and Adobe Commerce) |
| PHP | 8.2, 8.3, 8.4 |
| Cron | Magento's `default` group, for the periodic check |

## Installation

```bash
composer require calmfox/magento-smtp-module
bin/magento module:enable Calmfox_Smtp
bin/magento setup:upgrade
bin/magento cache:flush
```

Then *Stores → Configuration → Advanced → E-mail sending (SMTP)*: pick a provider, paste the two
credentials, save, and press **Check the connection now**.

## The settings

*Stores → Configuration → Advanced → E-mail sending (SMTP)*, all of it per store view.

| Group | What it is for |
|---|---|
| **Sending** | The one switch. Off means Magento sends the way it did before. |
| **Server** | Provider, server, port, encryption, credentials, timeout, certificate checking — and the button that checks them. |
| **Sender** | Overrides the sender address and the envelope sender, for providers that only accept their own. |
| **Health check** | Whether to check, how often, and how much patience to have before a problem is called one. |
| **Who is told** | The four channels, and where the e-mail and the webhook go. |
| **Log** | Whether to keep one, whether to keep bodies, and for how long. |
| **Test message** | Where to send a real one, and the button that does. |

The defaults are chosen so that installing the module changes nothing: sending stays with Magento
until a provider is picked and the switch is turned on.

## The console

Every screen has an equivalent, so an outside monitor can ask the same questions:

```bash
bin/magento calmfox:smtp:health            # 0 works, 1 a problem that may pass, 2 broken, 3 not configured
bin/magento calmfox:smtp:health --json     # the whole verdict, credentials excluded
bin/magento calmfox:smtp:health --force    # check now rather than reusing a recent verdict
bin/magento calmfox:smtp:test you@example.com
bin/magento calmfox:smtp:log:prune --days=7
```

The exit code is the interface for Zabbix, for a systemd timer, for a line in somebody's own cron,
and for a deployment script that would rather not go live with a shop that cannot e-mail anybody.

## Several store views

Settings are read per store view, so a shop with a Polish and a German storefront can send each
one's mail through a different provider. The check is run once per distinct set of settings and
the verdict recorded for every store view that shares it — dialling the same server four times to
learn the same thing is how a module gets itself rate limited. The report lists every store view,
and the red bar is about whichever one is worst off, because a shop with one broken sender is a
broken shop.

## Permissions

Three, because three different people need them: `Calmfox_Smtp::health` for the report (harmless,
useful to anybody handling orders), `Calmfox_Smtp::log` for the log (customer correspondence) and
`Calmfox_Smtp::config` for the settings (a credential that can send mail as the shop).

## Tests

The part with the decisions in it — the provider presets, the settings they resolve to, the SMTP
conversation, the reading it gives a shopkeeper, the choice to warn or stay quiet — knows nothing
about Magento and is tested against written-down transcripts of what real mail servers say,
including the ones nobody can arrange on demand: a refused password, a handshake that fails
halfway, a server that answers in HTTP.

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

## The same thing for Sylius

[calmfox/sylius-smtp-plugin](https://github.com/calmfoxpl/sylius-smtp-plugin) is this module's
counterpart for Sylius 2: the same provider catalogue, the same health check and the same wording,
built on Symfony Mailer and Sylius' own admin panel.

## Licence

MIT. See [LICENSE](LICENSE).
