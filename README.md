# Storm Projector

Checkpointed projections over the event store: fold the streams into read-model tables, derive new
streams from existing ones, or run one-off in-memory queries. One `projections` row per projection
holds state + checkpoint + lease; a batch is atomic (read + apply + checkpoint in one transaction)
and bounded by the store's commit-order safe head, so a projection never skips an in-flight
position — catch-up is exact, not eventual.

## When you reach for it

- **building a read model**: implement `ReadModel` (+ `FilteredProjection` for an event-type
  filter), mark it `#[AsProjection]`, run it with `storm:projection:run` — the daily driver;
- **deriving a stream**: a `LinkProjection` / `FanOutLinkProjection` produces a derived stream as
  event LINKS (rows in `event_links`, never copies); a `DerivedStreamProjection` consumes one;
- **an ad-hoc fold**: the `QueryFold` recipe — in-memory state, no checkpoint, no registration;
- **read-your-writes**: `ProjectionFreshness` (Contracts) waits for a projection to reach a
  `Position` — the HTTP edge's freshness strategy;
- **forgetting a person**: a projection holding personal data volunteers via `ForgetsSubject`, and
  the privacy forget calls every volunteer.

## The projection kinds

| Kind | Output | Bound by |
|---|---|---|
| `ReadModel` | its own table(s), DDL self-mounted in `initialize()` — or Doctrine-migration-owned via `MigratedReadModel` | safe head |
| `GroupedReadModel` | a keyed table, rows addressed by `groupKeys()` (subject-shaped ids) | safe head |
| `LinkProjection` / `FanOutLinkProjection` | a derived stream in `event_links` — links, never copies | safe head, contiguous (no event filter) |
| `DerivedStreamProjection` | its own table(s), fed through a producer's links | the PRODUCER's link head |
| `QueryProjection` (via `QueryFold`) | in-memory state | the run's own scope |

`#[AsProjection]` is parameterless on purpose: the topology is read from the projection itself —
name, categories, event types, target stream, and the mode from which sub-type it implements.

## Engine guarantees

- **One cursor.** A projection advances a single checkpoint; batches commit read + apply +
  checkpoint atomically on the projection's own connection.
- **Safe-head bounded.** Filtered projections advance only under the store's commit-order
  watermark, so a gap that later commits is never silently skipped.
- **Lease-owned, but never lease-dependent.** Correctness comes from the per-batch checkpoint row
  lock and the monotonic sequence — double-apply is impossible with or without the lease. The lease
  only stops a stalled worker from spinning as a zombie; losing it (`LeaseLost`) is a clean
  hand-off, the new owner already advancing.
- **CAS lifecycle.** `idle / running / paused / stopping / failed` transitions are compare-and-swap
  marks — `storm:projection:mark:pause|resume|stop` signal a live daemon from another process, and
  a stale transition loses loudly instead of overwriting.
- **Homed.** A projection's home connection derives from its KIND (read models live on the
  read-model store); the lifecycle RECEIVES its connection and asserts the home — nothing
  re-resolves a connection mid-run.

## Derived streams

A producer certifies its links up to a head; consumers read through the links and are bounded by
that head, so a consumer can never outrun what the producer has certified. Two hard rules:

- **membership is versioned**: a consumer is bound to its source stream's membership revision,
  checked at run start and at every cycle boundary — a producer rebuild refuses the consumer within
  one batch, rather than burying a link under its checkpoint;
- **checkpoints move as an ENSEMBLE**: producer and consumer state never advance independently past
  each other; when coherence cannot be proven the run REFUSES instead of coordinating.

Freshness for a derived consumer is measured against its producer's link head, never the global
safe head. Retiring a producer (`storm:projection:retire --orphan-stream`) can keep the derived
stream alive for its consumers.

## The query lane

`QueryProjectionRunner::fold($projection)` is the one-use recipe: scope by stream/events, run with
an intent-named terminal — `once()` / `drain()` / `follow()` — typed state out, a second terminal
refused (`FoldAlreadyRun`). The runner's `run()` / `stream()` stay as the arbitrary-filter escape
hatch.

## Personal data

The read-model half of crypto-shredding is opt-in per projection: the `rm_*` tables are yours, the
framework knows nothing of their columns, so a projection that holds personal data implements
`ForgetsSubject`. The privacy forget destroys the subject's cipher key, then runs every volunteer —
each home's volunteers inside one transaction, and the report names the registered projections that
did NOT volunteer: a compliance answer that hides what it skipped is a lie. A reset + rebuild
remains the catch-up path: a refold reads through the ciphering codec and folds declared fallbacks.

## Operating

`storm:projection:run <name>` (drain / daemon / once) is the runner;
`list` / `status` inspect, `mark:pause` / `mark:resume` / `mark:stop` signal,
`retry` re-arms a failed projection, `reset` refolds from zero, `delete` removes it
(a migrated read model empties its content instead of dropping the table),
`retire` detaches a producer, and `forget` clears the tracking row of an orphan whose code
was removed. All under the `storm:projection:` namespace.

## Contributor doctrine — the load-bearing rules

**1. One cursor, ever.** The single-checkpoint model is locked: no second cursor, no multiplexed
positions — orderings the head cannot prove are refused, not coordinated around.

**2. The safe head is the only skip-proof bound.** No proxy (transaction ids, wall clocks) may
substitute for it; a bound you cannot prove commit-ordered is not a bound.

**3. Refuse rather than coordinate.** Wherever two states could drift — derived checkpoints,
membership revisions, homes — the design makes the run refuse loudly instead of adding a
coordination protocol.

**4. Links, never copies.** A derived stream is rows pointing at the source events; duplicating
payloads into a derived store forks the truth and breaks erasure.

**5. Lifecycle by CAS marks.** Status changes are compare-and-swap transitions; an in-place status
write, however convenient, reintroduces the lost-update the marks exist to prevent.

## Tests

```bash
vendor/bin/phpunit src/Projector/Tests            # unit, from the storm root
vendor/bin/phpunit tests/Integration/Projector    # real PostgreSQL
```

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
