<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use RuntimeException;

/**
 * Thrown by a recipe's exercise to fail the lifecycle under the recipe's own fixed labels.
 *
 * The lifecycle's outcome is the only truth about a run, so an exercise never swallows a failure. A recipe records the
 * detail its report needs, then throws this, including for a failed expectation in a capture that otherwise finished.
 * Anything else that escapes an exercise is reported as `exercise_failure`.
 */
final class ExerciseFailure extends RuntimeException
{
    /** @param non-empty-list<string> $labels Fixed failure labels, which enter the report as they are. */
    public function __construct(public readonly array $labels)
    {
        parent::__construct('Disposable account exercise failed.');
    }
}
