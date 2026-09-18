<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml\System\Config;

use Calmfox\Smtp\Core\Provider\Provider;
use Calmfox\Smtp\Core\Provider\ProviderCatalog;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The panel under the provider list that says what that provider means by "user name".
 *
 * This is the module's answer to its own commonest support question. Every provider uses the two
 * credential fields differently, and the difference is never guessable: SendGrid wants the
 * literal word `apikey`, Postmark wants one token twice, Amazon wants credentials that look
 * exactly like the AWS keys that will not work. A shopkeeper who has to find that out from a
 * documentation site has already had a bad afternoon.
 *
 * The whole catalogue is handed to the browser, translated, so that changing the provider fills
 * in the server, the port and the encryption and rewrites this panel without a page reload — and
 * so that the fields it pins are pinned in front of the person typing, rather than corrected
 * silently after they save.
 */
class ProviderHelp extends Field
{
    protected $_template = 'Calmfox_Smtp::system/config/provider-help.phtml';

    /** The scope and inheritance columns mean nothing for a block of prose. */
    public function render(AbstractElement $element): string
    {
        return $this->_decorateRowHtml($element, '<td colspan="4">' . $this->_toHtml() . '</td>');
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /** @return array<string, array<string, mixed>> the catalogue as the browser needs it */
    public function getPresets(): array
    {
        $presets = [];
        foreach (ProviderCatalog::all() as $provider) {
            $presets[$provider->id] = [
                'label' => $provider->label,
                'host' => $provider->host,
                'port' => $provider->port,
                'encryption' => $provider->encryption,
                'auth' => $provider->authMethod,
                'pinnedUsername' => $provider->pinnedUsername,
                'tokenInBothFields' => $provider->tokenInBothFields,
                'hints' => $this->hints($provider),
                'hostVariants' => $this->hostVariants($provider),
                'docsUrl' => $provider->docsUrl,
            ];
        }

        return $presets;
    }

    public function getPresetsJson(): string
    {
        return (string) json_encode($this->getPresets(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> the html ids the browser has to fill in */
    public function getFieldIds(): array
    {
        return [
            'provider' => 'calmfox_smtp_server_provider',
            'host' => 'calmfox_smtp_server_host',
            'port' => 'calmfox_smtp_server_port',
            'encryption' => 'calmfox_smtp_server_encryption',
            'auth' => 'calmfox_smtp_server_auth',
            'username' => 'calmfox_smtp_server_username',
        ];
    }

    /** @return list<string> */
    private function hints(Provider $provider): array
    {
        $translated = [];
        foreach ($provider->hints() as $hint) {
            $translated[] = (string) __($hint);
        }

        return $translated;
    }

    /** @return array<string, string> */
    private function hostVariants(Provider $provider): array
    {
        $variants = [];
        foreach ($provider->hostVariants as $region => $host) {
            $variants[(string) __($region)] = $host;
        }

        return $variants;
    }
}
