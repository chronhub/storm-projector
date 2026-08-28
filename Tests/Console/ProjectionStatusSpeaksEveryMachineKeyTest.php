<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Stream\StreamName;

/**
 * Every key `ProjectionRow` serves on the wire is either rendered to a human or listed here as a
 * deliberate omission, with the reason it is one.
 *
 * The class of defect this closes, rather than its instances: a field lands on the row, `toArray()`
 * carries it, `--json` is honest, and the human renderer never learns about it. Six keys had gone
 * that way, `generation` and `source_revision` among them, which are two of the three grounds a run
 * refuses on; the refusal names this very command, so it answered the question with the question.
 *
 * The sibling of {@see \Storm\Saga\Tests\Console\HumanRenderSpeaksEveryMachineKeyTest}, and a sibling
 * rather than a shared base on purpose: a module's console surface owns its own inventory, and Saga
 * does not depend on Projector.
 */
final class ProjectionStatusSpeaksEveryMachineKeyTest extends TestCase
{
    /**
     * Every surface that puts this row in front of a reader, by path from `src/Projector/`.
     *
     * A DTO has more than one renderer, and the one that drifts is the one nobody listed. Naming a
     * PATH rather than importing a class is deliberate: the row is this module's, so the question
     * "does every surface speak every key" is this module's too, and asking it must not create a
     * dependency the layer rules forbid.
     *
     * @var list<string>
     */
    private const array RENDERERS = [
        'Console/StatusProjectionCommand.php',
        '../ApiOps/State/ProjectionResourceFactory.php',
    ];

    /**
     * Keys the human channel deliberately does not render, and why.
     *
     * Adding a key here is a decision an author owes an argument for; a key that reaches neither list
     * fails the test until somebody makes that decision.
     *
     * @var array<string, string>
     */
    private const array OMITTED = [];

    /**
     * The keys the renderer puts in front of a human.
     *
     * Written by hand, and therefore able to drift the way any inventory does; what holds it to the
     * code is the third check below, which reads the renderer's own source and refuses a claim the
     * source does not back. That check proves the datum is NAMED, never that it is shown well.
     *
     * @var list<string>
     */
    private const array RENDERED = [
        'name', 'status', 'last_position', 'mode', 'categories', 'event_classes', 'source_stream',
        'source_revision', 'target_stream', 'target_prefix', 'lease_owner', 'lease_until',
        'last_heartbeat_at', 'pause_until', 'generation', 'failed_at', 'error_class', 'error_message',
    ];

    #[Test]
    public function every_machine_key_is_rendered_or_deliberately_omitted(): void
    {
        $decided = [...self::RENDERED, ...array_keys(self::OMITTED)];

        foreach (array_keys(self::row()->toArray()) as $key) {
            self::assertContains(
                $key,
                $decided,
                sprintf(
                    'ProjectionRow serves "%s" on the wire and the human render neither shows it nor '
                    .'declines it. Render it, or add it to OMITTED with the reason.',
                    $key,
                ),
            );
        }
    }

    #[Test]
    public function no_decision_outlives_the_key_it_was_about(): void
    {
        // the other direction: a key renamed or dropped leaves its entry standing, and the next author
        // reads a settled argument about a field that no longer exists
        $machineShape = self::row()->toArray();

        foreach ([...self::RENDERED, ...array_keys(self::OMITTED)] as $decided) {
            self::assertArrayHasKey(
                $decided,
                $machineShape,
                sprintf('ProjectionRow no longer serves "%s"; drop the stale decision.', $decided),
            );
        }
    }

    /**
     * The inventory is held to the code it describes: every key claimed rendered is named by the
     * renderer's own source.
     *
     * A source read, not a rendering: it proves the datum reaches the renderer, never that it lands
     * where an operator looks. That limit is why an omission still owes its reason in prose.
     */
    #[Test]
    public function every_key_claimed_rendered_is_named_by_every_renderer(): void
    {
        foreach (self::RENDERERS as $renderer) {
            $source = file_get_contents(dirname(__DIR__, 2).'/'.$renderer);
            self::assertIsString($source, sprintf('the renderer %s is unreadable', $renderer));

            foreach (self::RENDERED as $key) {
                self::assertStringContainsString(
                    '->'.self::accessor($key),
                    $source,
                    sprintf(
                        'ProjectionRow lists "%s" as rendered, and %s never reads it. Render it, or '
                        .'move it to OMITTED with the reason it is not shown.',
                        $key,
                        $renderer,
                    ),
                );
            }
        }
    }

    /**
     * The wire key as the property a renderer reads: the row is a promoted-constructor DTO, so its
     * camel-cased property is the wire key's own name.
     */
    private static function accessor(string $wireKey): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $wireKey))));
    }

    private static function row(): ProjectionRow
    {
        return new ProjectionRow(
            name: 'account_balance',
            status: ProjectionStatus::Running,
            lastPosition: 42,
            mode: 'read_model',
            categories: ['account'],
            eventClasses: [],
            sourceStream: new StreamName('account-feed'),
            sourceRevision: 3,
            targetStream: null,
            targetPrefix: null,
            leaseOwner: 'worker-1',
            leaseUntil: null,
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 2,
        );
    }
}
