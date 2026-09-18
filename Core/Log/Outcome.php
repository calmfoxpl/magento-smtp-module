<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Log;

/** What became of one message. */
final class Outcome
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Magento had sending switched off, so the message was dropped before it reached us. */
    public const SUPPRESSED = 'suppressed';

    public const ALL = [self::SENT, self::FAILED, self::SUPPRESSED];
}
