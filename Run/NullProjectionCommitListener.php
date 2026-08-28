<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Contracts\Projector\ProjectionCommitListener;

/**
 * The default no-op of the contracted post-commit hook: a projection run costs nothing extra until
 * something opts in, such as the HTTP bridge's cache purger.
 */
final readonly class NullProjectionCommitListener implements ProjectionCommitListener
{
    public function committed(string $projection): void {}
}
