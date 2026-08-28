<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * The read-model half of crypto-shredding, opt-in per projection: the `rm_*` tables are the dev's by
 * the homing doctrine, the framework knowing nothing of their columns, so forgetting a subject there
 * cannot be automatic. A projection that HOLDS personal data volunteers by implementing this, and
 * `storm:privacy:forget` calls every volunteer after destroying the subject's cipher key, each home's
 * volunteers inside ONE transaction on that home, so a partial forget never commits.
 *
 * The forget's report names both sides: the volunteers it ran, and the registered read-model
 * projections that did NOT volunteer; a compliance answer that hides what it skipped is a lie.
 * The natural implementors are the keyed views: a `GroupedReadModel` already addresses its rows by
 * subject-shaped keys. A projection reset + rebuild remains the catch-up path: a refold reads
 * through the ciphering codec and folds the declared fallbacks.
 *
 * Module-local port by the placement rule: the signature speaks DBAL, so it cannot live in
 * Contracts. Lives with the projections because they are who implements it.
 *
 * @see \Storm\Contracts\Serializer\CipherKeyStore the key half of the same forget
 */
interface ForgetsSubject
{
    /**
     * Erase or redact this projection's rows for the subject, through the SAME transaction the
     * orchestrator opened on this projection's home. Must be idempotent: a re-forget re-runs every
     * volunteer, and "nothing left to erase" is success, not an error.
     *
     * @param  string  $subject  the subject's opaque id, the value of the `#[Personal]` subject key
     *
     * @throws Throwable any failure, such as a DBAL write; the orchestrator rolls back this home's
     *                   transaction and reports the forget incomplete
     */
    public function forgetSubject(string $subject, Connection $tx): void;
}
