<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

/** DNS could not answer. Not the same as answering "there is nothing there". */
final class LookupFailed extends \RuntimeException
{
}
