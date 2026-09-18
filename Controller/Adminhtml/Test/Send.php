<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Test;

use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Mail\TestSender;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Sends one test message and reports what the server said about it.
 *
 * The success wording is careful on purpose. "Accepted by the server" is all we know, and a
 * module that answers "sent successfully" teaches shopkeepers to trust a green tick that cannot
 * see a spam folder. So it says what happened and then says where to look.
 */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::config';

    public function __construct(
        Action\Context $context,
        private readonly TestSender $sender,
        private readonly Config $config,
        private readonly JsonFactory $results,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $recipient = trim((string) ($this->getRequest()->getParam('recipient') ?: $this->config->testRecipient($storeId)));

        if ('' === $recipient) {
            return $this->results->create()->setData([
                'ok' => false,
                'headline' => (string) __('There is nobody to send a test message to.'),
                'lines' => [(string) __('Fill in an address above, or set the address warnings go to.')],
            ]);
        }

        try {
            $milliseconds = $this->sender->send($recipient, $storeId);
        } catch (\Throwable $failure) {
            return $this->results->create()->setData([
                'ok' => false,
                'headline' => (string) __('The message was not sent.'),
                'lines' => [$failure->getMessage()],
            ]);
        }

        return $this->results->create()->setData([
            'ok' => true,
            'headline' => (string) __('The server accepted a message for %1 in %2 ms.', $recipient, $milliseconds),
            'lines' => [
                (string) __('Look in that mailbox now. If it is there, sending works.'),
                (string) __('If it is in the spam folder, the settings are right and the next thing to look at is the domain\'s SPF, DKIM and DMARC records.'),
                (string) __('If it is nowhere at all within a few minutes, the provider took it and dropped it: their own dashboard will say why.'),
            ],
        ]);
    }
}
