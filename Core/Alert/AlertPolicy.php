<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Alert;

use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Core\Health\Thresholds;

/**
 * Whether to interrupt somebody's afternoon.
 *
 * The rules are short and they are all about trust. Report a failure once, when it is first
 * called a failure. Report it again if the reason changes, because "the password is refused" and
 * "the port is closed" are different jobs. Repeat it after a long silence, because a shop that
 * has not sent an order confirmation since Tuesday deserves a second knock. And say when it is
 * over, because a warning nobody is told the end of is a warning that gets ignored next time.
 *
 * Everything else is silence. In particular a single failed check is never reported: the
 * evaluator keeps it at `warn`, and `warn` lives in the panel, not in somebody's inbox.
 */
final class AlertPolicy
{
    public function __construct(private readonly Thresholds $thresholds = new Thresholds())
    {
    }

    public function decide(HealthState $state, int $now): Alert
    {
        if (Status::FAIL === $state->status) {
            if (null === $state->notifiedCause) {
                return Alert::problem($state->cause);
            }
            if ($state->notifiedCause !== $state->cause) {
                return Alert::problem($state->cause);
            }
            if (null === $state->notifiedAt || $now - $state->notifiedAt >= $this->thresholds->repeatAfterSeconds) {
                return Alert::problem($state->cause, true);
            }

            return Alert::none();
        }

        if (Status::OK === $state->status && null !== $state->notifiedCause) {
            return Alert::recovery($state->notifiedCause);
        }

        return Alert::none();
    }
}
