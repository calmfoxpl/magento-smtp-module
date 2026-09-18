# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-09-18

### Added

- SMTP sending for Magento 2 in front of the platform's own transport, with Magento's *Disable
  Email Communications* honoured above everything and a fallback to Magento's transport whenever
  the module is off or configured too incompletely to send.
- Ready-made settings for fifteen providers, each with the server, port and encryption filled in
  and the provider's own meaning of the two credential fields explained next to the fields —
  including the logins that are pinned (SendGrid, Resend, SparkPost) and the token Postmark wants
  twice.
- A periodic health check that connects, raises encryption and logs in without sending a message,
  reporting the stage it reached, the cause, the server's own words and how long it took.
- A diagnosis layer that turns operating system, TLS and provider errors into one named cause and
  one provider-specific thing to check.
- Warnings through four channels — a bar across the admin panel, an e-mail sent via the server's
  local mail command, a webhook, and the log plus the exit code of `calmfox:smtp:health` — spoken
  once, again on a changed reason, once more after a long silence, and once when it is over.
- Watching of real sends, so a shop whose messages are failing is reported as broken whatever a
  connection test says, and a successful send clears the record.
- A log of what was sent, with subjects decoded, credentials redacted, optional message bodies,
  a resend button for failures, nightly pruning and a retention that applies whether or not the
  log is switched on.
- A report page, a dashboard notice that appears only when sending is broken, and the console
  commands `calmfox:smtp:health`, `calmfox:smtp:test` and `calmfox:smtp:log:prune`.
- Per store view settings, with one check per distinct set of them.
- English and Polish translations.

[Unreleased]: https://github.com/calmfoxpl/magento-smtp-module/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/calmfoxpl/magento-smtp-module/releases/tag/v1.0.0
