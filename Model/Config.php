<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model;

use Calmfox\Smtp\Core\Health\Thresholds;
use Calmfox\Smtp\Core\Log\Retention;
use Calmfox\Smtp\Core\Settings\ResolvedSettings;
use Calmfox\Smtp\Core\Settings\SettingsResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to *Stores → Configuration → Advanced → Mail Sending (Calmfox SMTP)*.
 *
 * Everything is read per store view, because a shop with a Polish and a German storefront often
 * sends each one's mail from a different address, and occasionally through a different provider.
 * The resolved settings are memoised per store for the length of the request: a page that sends
 * three e-mails should not decrypt the password three times.
 */
class Config
{
    public const XML_PATH = 'calmfox_smtp/';

    /** Magento's own mail settings, which can switch sending off entirely. */
    public const MAGENTO_SMTP_PATH = 'system/smtp';

    /** @var array<string, ResolvedSettings> */
    private array $resolved = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly SettingsResolver $resolver,
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag('general/enabled', $storeId);
    }

    /** What the shop will send with, and everything questionable about it. */
    public function resolve(?int $storeId = null): ResolvedSettings
    {
        $key = null === $storeId ? 'default' : (string) $storeId;

        return $this->resolved[$key] ??= $this->resolver->resolve(
            [
                'enabled' => $this->isEnabled($storeId),
                'provider' => $this->value('server/provider', $storeId),
                'host' => $this->value('server/host', $storeId),
                'port' => $this->value('server/port', $storeId),
                'encryption' => $this->value('server/encryption', $storeId),
                'auth' => $this->value('server/auth', $storeId),
                'username' => $this->value('server/username', $storeId),
                'password' => $this->password($storeId),
                'timeout' => $this->value('server/timeout', $storeId),
                'verify_certificate' => $this->flag('server/verify_certificate', $storeId),
                'from_name' => $this->value('sender/from_name', $storeId),
                'from_email' => $this->value('sender/from_email', $storeId),
                'return_path' => $this->value('sender/return_path', $storeId),
            ],
            $this->magentoMailSettings($storeId),
        );
    }

    /** @return array<string, mixed> */
    public function magentoMailSettings(?int $storeId = null): array
    {
        $group = $this->scopeConfig->getValue(self::MAGENTO_SMTP_PATH, ScopeInterface::SCOPE_STORE, $storeId);

        return \is_array($group) ? $group : [];
    }

    /**
     * The password, which is stored the way Magento stores passwords.
     *
     * A value saved before the field became encrypted, or pasted straight into the database,
     * comes back from the decryptor as an empty string. Falling back to the stored value keeps
     * such a shop sending instead of silently losing its credential.
     */
    private function password(?int $storeId): string
    {
        $stored = $this->value('server/password', $storeId);
        if ('' === $stored) {
            return '';
        }

        $decrypted = $this->encryptor->decrypt($stored);

        return '' === trim($decrypted) ? $stored : $decrypted;
    }

    public function healthCheckEnabled(?int $storeId = null): bool
    {
        return $this->flag('health/enabled', $storeId);
    }

    public function thresholds(?int $storeId = null): Thresholds
    {
        return Thresholds::fromArray([
            'failed_checks' => $this->value('health/failed_checks', $storeId),
            'send_failures' => $this->value('health/send_failures', $storeId),
            'send_window' => $this->value('health/send_window', $storeId),
            'repeat_after' => $this->value('health/repeat_after', $storeId),
        ]);
    }

    /** How long a health verdict is reused before the server is dialled again. */
    public function checkEverySeconds(?int $storeId = null): int
    {
        return max(60, (int) $this->value('health/interval', $storeId));
    }

    public function dnsCheckEnabled(?int $storeId = null): bool
    {
        return $this->flag('dns/enabled', $storeId);
    }

    /**
     * The domain to ask about when no sender address has been filled in yet.
     *
     * This is what makes the suggestion useful at the moment it is needed — an empty settings
     * screen — and it is the shop's own address, which is the right guess and the only one we
     * have. It is never used for the verdict: judging a domain the shop has not said it sends
     * as would produce a complaint about somebody else's records.
     */
    public function senderDomainFallback(?int $storeId = null): ?string
    {
        try {
            $host = parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), \PHP_URL_HOST);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($host) || '' === $host) {
            return null;
        }

        // `www.` is a web host, not a mail domain, and the records live on the bare name.
        return mb_strtolower(preg_replace('/^www\./', '', $host) ?? $host);
    }

    public function warnsInPanel(?int $storeId = null): bool
    {
        return $this->flag('alerts/panel', $storeId);
    }

    public function warnsByEmail(?int $storeId = null): bool
    {
        return $this->flag('alerts/email', $storeId) && '' !== $this->alertRecipient($storeId);
    }

    /** Where the warning goes. Empty means nobody, and the panel says so. */
    public function alertRecipient(?int $storeId = null): string
    {
        $configured = $this->value('alerts/recipient', $storeId);
        if ('' !== $configured) {
            return $configured;
        }

        // Falling back to the shop's own general contact is better than not warning at all.
        return trim((string) $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function warnsByWebhook(?int $storeId = null): bool
    {
        return $this->flag('alerts/webhook', $storeId) && '' !== $this->webhookUrl($storeId);
    }

    public function webhookUrl(?int $storeId = null): string
    {
        return $this->value('alerts/webhook_url', $storeId);
    }

    public function logEnabled(?int $storeId = null): bool
    {
        return $this->flag('log/enabled', $storeId);
    }

    public function logsBody(?int $storeId = null): bool
    {
        return $this->flag('log/store_body', $storeId);
    }

    public function retentionDays(?int $storeId = null): int
    {
        return Retention::days($this->value('log/retention_days', $storeId));
    }

    /** Where a test message is sent when nobody says otherwise. */
    public function testRecipient(?int $storeId = null): string
    {
        $configured = $this->value('test/recipient', $storeId);

        return '' !== $configured ? $configured : $this->alertRecipient($storeId);
    }

    private function value(string $path, ?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH . $path, ScopeInterface::SCOPE_STORE, $storeId));
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
