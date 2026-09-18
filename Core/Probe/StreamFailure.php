<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Probe;

/** The connection broke while we were using it. Carries no diagnosis, only the plain fact. */
final class StreamFailure extends \RuntimeException
{
}
