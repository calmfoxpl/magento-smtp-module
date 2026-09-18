<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Text;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Diagnosis\Diagnosis;
use Calmfox\Smtp\Core\Diagnosis\Hint;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Core\Probe\Stage;
use Calmfox\Smtp\Core\Settings\Issue;
use Calmfox\Smtp\Core\Settings\IssueCode;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Magento\Framework\Phrase;

/**
 * Every sentence this module says to a person, in one file.
 *
 * The core decides things and names them with codes; this is where a code becomes language. Two
 * reasons for the split. One is translation: a sentence written inside the core could never be
 * translated, and the people who run these shops do not all read English. The other is tone —
 * having the wording in one place is what makes it possible to keep it consistent, and to read
 * it all in five minutes and ask whether a shopkeeper would actually understand it.
 *
 * The house rules for that wording:
 *
 *  - say what happened, then the one thing most worth checking, and stop,
 *  - never claim more than we know: the check proves a connection, never a delivery,
 *  - a hint is a hint ("most often", not "the problem is"),
 *  - no protocol vocabulary where a plain word exists, and the server's own words kept
 *    verbatim where they do not, because that is what a hosting company will ask for.
 */
class Wording
{
    /** A status line: three or four words, for a badge or a column. */
    public function status(string $status): Phrase
    {
        return match (Status::normalize($status)) {
            Status::OK => __('Sending works'),
            Status::WARN => __('A problem that may pass'),
            Status::FAIL => __('Sending is broken'),
            default => __('Not checked yet'),
        };
    }

    /** What went wrong, short enough for a heading. */
    public function cause(string $cause): Phrase
    {
        return match ($cause) {
            Cause::OK => __('Working'),
            Cause::DNS_FAILURE => __('Unknown server name'),
            Cause::CONNECT_REFUSED => __('Connection refused'),
            Cause::CONNECT_TIMEOUT => __('No answer'),
            Cause::NETWORK_UNREACHABLE => __('No route to the server'),
            Cause::TLS_HANDSHAKE_FAILED => __('Encryption could not be agreed'),
            Cause::CERTIFICATE_REJECTED => __('Certificate not accepted'),
            Cause::NOT_SMTP => __('Not a mail server'),
            Cause::GREETING_REJECTED => __('Refused by the server'),
            Cause::SERVER_BUSY => __('Server busy'),
            Cause::EHLO_REJECTED => __('Session refused'),
            Cause::STARTTLS_UNSUPPORTED => __('No STARTTLS offered'),
            Cause::STARTTLS_REJECTED => __('STARTTLS refused'),
            Cause::AUTH_UNSUPPORTED => __('No way to log in'),
            Cause::AUTH_REJECTED => __('Credentials refused'),
            Cause::AUTH_TEMPORARY => __('Logging in postponed'),
            Cause::TIMEOUT => __('Stopped answering'),
            Cause::PROTOCOL_ERROR => __('Unreadable answer'),
            Cause::MESSAGE_REJECTED => __('Message refused'),
            Cause::NOT_CONFIGURED => __('Not configured'),
            // Spelled out rather than left to the default, so that a cause added later cannot
            // quietly inherit the wording for "we have no idea".
            Cause::UNKNOWN => __('Unknown failure'),
            default => __('Unknown failure'),
        };
    }

    /** The same thing as a sentence, about this shop's own server. */
    public function detail(string $cause, MailSettings $settings): Phrase
    {
        $where = $settings->endpoint();

        return match ($cause) {
            Cause::OK => __('The server at %1 accepts connections, and the credentials are accepted. This says nothing about delivery.', $where),
            Cause::DNS_FAILURE => __('The name %1 could not be looked up from this server.', $settings->host),
            Cause::CONNECT_REFUSED => __('Nothing is listening at %1.', $where),
            Cause::CONNECT_TIMEOUT => __('No answer from %1 within %2 seconds.', $where, $settings->timeout),
            Cause::NETWORK_UNREACHABLE => __('There is no route from this server to %1.', $where),
            Cause::TLS_HANDSHAKE_FAILED => __('The encrypted connection to %1 could not be agreed.', $where),
            Cause::CERTIFICATE_REJECTED => __('The certificate presented by %1 was not accepted.', $where),
            Cause::NOT_SMTP => __('Something answers at %1, but it does not speak SMTP.', $where),
            Cause::GREETING_REJECTED => __('The server at %1 refused the connection before anything was sent.', $where),
            Cause::SERVER_BUSY => __('The server at %1 asked us to come back later.', $where),
            Cause::EHLO_REJECTED => __('The server at %1 would not open a session.', $where),
            Cause::STARTTLS_UNSUPPORTED => __('The server at %1 does not offer STARTTLS, which these settings require.', $where),
            Cause::STARTTLS_REJECTED => __('The server at %1 refused to turn on encryption.', $where),
            Cause::AUTH_UNSUPPORTED => __('The server at %1 offers no way to present the credentials that are configured.', $where),
            Cause::AUTH_REJECTED => __('The server at %1 refused the user name and password.', $where),
            Cause::AUTH_TEMPORARY => __('The server at %1 could not check the credentials this time.', $where),
            Cause::TIMEOUT => __('The connection to %1 stopped answering part way through.', $where),
            Cause::PROTOCOL_ERROR => __('The server at %1 answered in a way we could not read.', $where),
            Cause::MESSAGE_REJECTED => __('The server at %1 took the connection but refused the message.', $where),
            Cause::NOT_CONFIGURED => __('The settings are not complete enough to try sending.'),
            Cause::UNKNOWN => __('Sending to %1 failed for a reason we could not name.', $where),
            default => __('Sending to %1 failed for a reason we could not name.', $where),
        };
    }

    /** The one thing most worth checking, given the provider. Never more than one. */
    public function hint(string $hint): Phrase
    {
        return match ($hint) {
            Hint::SES_SMTP_CREDENTIALS => __('Amazon SES needs SMTP credentials created under its SMTP settings. An AWS access key and secret are a different thing and will always be refused here.'),
            Hint::SES_SANDBOX => __('A new Amazon SES account may only write to verified addresses until Amazon takes it out of the sandbox.'),
            Hint::GMAIL_APP_PASSWORD => __('Google wants an App password here rather than the account password, and only issues one once two-step verification is on.'),
            Hint::MICROSOFT_SMTP_AUTH => __('Microsoft 365 turns SMTP AUTH off by default; it has to be switched on for this mailbox in the Microsoft 365 admin centre.'),
            Hint::SENDGRID_PINNED_LOGIN => __('SendGrid expects the literal word apikey as the login, with the API key as the password.'),
            Hint::POSTMARK_TOKEN => __('Postmark expects the Server API token in both the user name and the password.'),
            Hint::MAILGUN_REGION => __('A Mailgun domain created in the European region answers only on the European host.'),
            Hint::ZOHO_REGION => __('Zoho keeps each data centre on its own address, and it has to be the one the account lives in.'),
            Hint::BREVO_SMTP_KEY => __('Brevo wants the SMTP key from its SMTP & API page, not the password used to sign in.'),
            Hint::MAILJET_KEY_PAIR => __('Mailjet uses the API key as the login and the secret key as the password.'),
            Hint::PORT_BLOCKED => __('A port that answers nothing at all is most often one the hosting company blocks outbound. Ask them to open it, or use the alternative port the provider offers.'),
            Hint::MAILBOX_LIMIT => __('A mailbox is not a sending service. This one has a daily limit a shop reaches on its first busy day, and every recipient counts towards it.'),
            Hint::CERTIFICATE => __('Check that the address matches the name on the certificate and that the certificate has not expired.'),
            Hint::WRONG_PORT_FOR_ENCRYPTION => __('Port and encryption disagree: 465 expects TLS from the first byte, 587 expects STARTTLS.'),
            default => __('There is nothing more we can say about this one.'),
        };
    }

    /** How far the conversation got, as a phrase that fits after "while". */
    public function stage(string $stage): Phrase
    {
        return match ($stage) {
            Stage::CONNECT => __('connecting'),
            Stage::GREETING => __('reading the greeting'),
            Stage::EHLO => __('opening the session'),
            Stage::STARTTLS => __('asking for encryption'),
            Stage::HANDSHAKE => __('agreeing the encryption'),
            Stage::AUTH => __('logging in'),
            default => __('finishing'),
        };
    }

    public function issue(Issue $issue): Phrase
    {
        $parameters = $issue->params;

        return match ($issue->code) {
            IssueCode::EMAIL_DISABLED_IN_MAGENTO => __('Magento is set to send no e-mail at all. Until Disable Email Communications is turned off, nothing leaves the shop however this is configured.'),
            IssueCode::HOST_MISSING => __('There is no server address.'),
            IssueCode::PORT_INVALID => __('%1 is not a port number.', ...$parameters),
            IssueCode::USERNAME_MISSING => __('There is no user name, although the settings say to log in.'),
            IssueCode::PASSWORD_MISSING => __('There is no password, although the settings say to log in.'),
            IssueCode::ENCRYPTION_MISSING_ON_IMPLICIT_PORT => __('Port %1 expects TLS from the first byte. With encryption off, the server will never answer.', ...$parameters),
            IssueCode::IMPLICIT_TLS_ON_STARTTLS_PORT => __('Port %1 usually expects STARTTLS rather than TLS from the first byte.', ...$parameters),
            IssueCode::STARTTLS_ON_IMPLICIT_PORT => __('Port %1 usually expects TLS from the first byte rather than STARTTLS.', ...$parameters),
            IssueCode::CREDENTIALS_WITHOUT_ENCRYPTION => __('The password would travel across the network unencrypted.'),
            IssueCode::PORT_25 => __('Port 25 is blocked outbound by most hosting companies, and by most home connections.'),
            IssueCode::CERTIFICATE_CHECK_OFF => __('The certificate is not being checked, so nothing proves the server is the one it claims to be.'),
            IssueCode::FROM_EMAIL_INVALID, IssueCode::RETURN_PATH_INVALID => __('%1 is not an e-mail address.', ...$parameters),
            IssueCode::MAGENTO_SMTP_ALSO_CONFIGURED => __('Magento\'s own mail settings point at %1. They are ignored while this module is on, but they will mislead whoever reads them next.', ...$parameters),

            // What the sender domain publishes. These never say "broken": the shop is sending,
            // and the decision that goes against it is made at the far end, by a receiver.
            IssueCode::SENDER_DOMAIN_UNKNOWN => __('We cannot tell which domain this shop sends as, so its records were not checked. Fill in the sender address, or use a login that is an address at the shop\'s own domain.'),
            IssueCode::DNS_UNAVAILABLE => __('The domain\'s records could not be read from this server, so nothing is known about them either way. That is usually DNS on the server rather than anything about the shop.'),
            IssueCode::SPF_MISSING => __('%1 publishes no SPF record, so nothing says which servers may send as it. Gmail and Outlook treat mail from such a domain as suspicious by default.', ...$parameters),
            IssueCode::SPF_MULTIPLE => __('%1 publishes two SPF records. A receiving server reads that as a permanent error and ignores both, so the domain authorises nobody at all — they have to be merged into one.', ...$parameters),
            IssueCode::SPF_DOES_NOT_AUTHORIZE_PROVIDER => __('The SPF record of %1 does not authorise the provider this shop sends through. Until it contains %2, the provider will keep accepting the messages and receivers will keep treating them as forged.', ...$parameters),
            IssueCode::SPF_LOOKUP_LIMIT => __('Evaluating this SPF record costs %1 DNS lookups and receivers give up after %2. Past that the record is ignored outright, however sensible it looks.', ...$parameters),
            IssueCode::SPF_ALL_PERMISSIVE => __('The SPF record of %1 ends with +all, which authorises every server on the internet to send as it. That is worse than publishing nothing, because it looks like protection.', ...$parameters),
            IssueCode::SPF_NO_ALL => __('The SPF record says nothing about senders it has not listed. Ending it with ~all, or with -all once everything is verified, is what makes it mean something.'),
            IssueCode::DKIM_MISSING_FOR_PROVIDER => __('No DKIM key for this provider is published at %1; we looked for %2._domainkey. Gmail and Yahoo have expected bulk senders to sign their mail since 2024, and unsigned mail from a shop is increasingly filed as spam.', ...$parameters),
            IssueCode::DMARC_MISSING => __('%1 publishes no DMARC record, so nobody is told when somebody forges the shop\'s address and receivers have no instruction about what to do with it.', ...$parameters),
            IssueCode::DMARC_MONITOR_ONLY => __('The DMARC record of %1 only watches: a forged sender is reported and delivered anyway. That is the right first step, and worth moving past once SPF and DKIM are in order.', ...$parameters),

            default => __('Something about these settings is wrong.'),
        };
    }

    /**
     * One line that says everything: the state, the stage and the place.
     *
     * This is what goes in the red banner, in the alert e-mail's subject and in the console. One
     * sentence, because it is read by somebody who is in the middle of something else.
     */
    public function headline(HealthState $state, MailSettings $settings): Phrase
    {
        if (Status::OK === $state->status) {
            return __('Sending through %1 is working.', $settings->endpoint());
        }
        if (Status::UNKNOWN === $state->status) {
            return __('Sending through %1 has not been checked yet.', $settings->endpoint());
        }

        return __(
            'The shop cannot send e-mail: %1 while %2, at %3.',
            $this->cause($state->cause),
            $this->stage($state->stage),
            $settings->endpoint(),
        );
    }

    /**
     * The heading for the sender domain's records, kept deliberately apart from "cannot send".
     *
     * The distinction is the whole point of having it. Red means no mail is leaving the shop and
     * somebody has to act within the hour. This means mail is leaving and may not be arriving,
     * which is a different job, usually for whoever looks after the domain. Saying both in the
     * same red box would rob the red one of its meaning within a week.
     */
    public function deliverabilityHeadline(?string $domain): Phrase
    {
        if (null === $domain) {
            return __('The shop is sending, but nothing was checked about the sender domain.');
        }

        return __('The shop is sending, but what %1 publishes means the mail may not be arriving.', $domain);
    }

    /** What the domain publishes, as a fact rather than a complaint. */
    public function domainFact(string $what, ?bool $state): Phrase
    {
        if (null === $state) {
            return __('not checked');
        }
        if ('spf_provider' === $what) {
            return $state ? __('authorises this provider') : __('does not authorise this provider');
        }

        return $state ? __('published') : __('not published');
    }

    /** The sentence that says it is over, which is the one that earns the next warning a reading. */
    public function recovery(MailSettings $settings): Phrase
    {
        return __('The shop can send e-mail again through %1.', $settings->endpoint());
    }

    /** Everything we know about a failure, in the order it is useful. @return list<Phrase> */
    public function explain(HealthState $state, MailSettings $settings): array
    {
        $lines = [$this->detail($state->cause, $settings)];

        $hint = Diagnosis::hint($state->cause, $settings);
        if (null !== $hint) {
            $lines[] = $this->hint($hint);
        }
        if ('' !== $state->reply && Cause::NOT_CONFIGURED !== $state->cause) {
            $lines[] = __('The server said: %1', $state->reply);
        }

        return $lines;
    }
}
