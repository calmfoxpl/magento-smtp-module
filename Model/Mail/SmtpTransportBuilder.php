<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Mail;

use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Laminas\Mail\Transport\Envelope;
use Laminas\Mail\Transport\Smtp;
use Laminas\Mail\Transport\SmtpOptions;

/**
 * Builds the Laminas SMTP transport that actually carries the message.
 *
 * Laminas is already in every Magento installation and Magento's own transport uses it, so this
 * adds no dependency and behaves the way the platform behaves. What it adds is the translation
 * from our settings to its vocabulary — including the one mapping worth explaining: `auto`
 * becomes LOGIN.
 *
 * Laminas does not negotiate a mechanism; it is told one. The health check does negotiate, and
 * reports what a server offers, so `auto` here means "the mechanism every provider in the
 * catalogue accepts" rather than "whatever is strongest". A shop that needs CRAM-MD5 can say so,
 * and the check will confirm the server offers it.
 */
class SmtpTransportBuilder
{
    public function build(MailSettings $settings, string $clientName = 'localhost'): Smtp
    {
        $connection = [];
        if (Encryption::NONE !== $settings->encryption) {
            $connection['ssl'] = $settings->encryption;
        }
        if ($settings->usesAuthentication()) {
            $connection['username'] = $settings->username;
            $connection['password'] = $settings->password;
        }
        if (!$settings->verifyCertificate) {
            // Only ever reached because somebody turned the check off and was warned about it.
            $connection['novalidatecert'] = true;
        }

        $transport = new Smtp(new SmtpOptions([
            'name' => $clientName,
            'host' => $settings->host,
            'port' => $settings->port,
            'connection_class' => $this->connectionClass($settings),
            'connection_config' => $connection,
        ]));

        if (null !== $settings->returnPath) {
            // The envelope sender, which is where bounces go — a different thing from the From
            // header, and the one providers actually check.
            $transport->setEnvelope(new Envelope(['from' => $settings->returnPath]));
        }

        return $transport;
    }

    private function connectionClass(MailSettings $settings): string
    {
        if (!$settings->usesAuthentication()) {
            return 'smtp';
        }

        return match ($settings->authMethod) {
            AuthMethod::PLAIN => 'plain',
            AuthMethod::CRAM_MD5 => 'crammd5',
            default => 'login',
        };
    }
}
