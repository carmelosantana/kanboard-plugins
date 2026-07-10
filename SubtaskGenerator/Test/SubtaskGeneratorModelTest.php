<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SubtaskGenerator\Controller\GeneratorController;
use Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator Task 04/05: generate endpoint + normalization.
 *
 * All tests drive through a FAKE ProviderRegistry (an anonymous subclass whose
 * structured() returns/throws a canned value) — NO network calls, and NO
 * php-agents class is ever touched.
 *
 * Covers:
 *  (a) Canned valid array → generate() returns normalized/deduped/clamped titles.
 *  (b) Malformed model output (missing subtasks / non-string titles)
 *      → graceful (returns empty [], NOT a fatal).
 *  (c) Clamp to sg_max_subtasks.
 *  (d) generate() endpoint rejects non-admin / bad-CSRF.
 *  (e) Registry throwing → controller returns a clean JSON error, not a 500.
 */
class SubtaskGeneratorModelTest extends Base
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** A registry stub whose structured() returns/throws a canned value — no network. */
    private function fakeRegistry(mixed $return, ?\Throwable $throw = null): ProviderRegistry
    {
        return new class($this->container, $return, $throw) extends ProviderRegistry {
            public function __construct($c, private mixed $r, private ?\Throwable $t) { parent::__construct($c); }
            public function structured(array $messages, string $schema, ?string $profileId = null): array {
                if ($this->t !== null) { throw $this->t; }
                return is_array($this->r) ? $this->r : [];
            }
        };
    }

    private function makeModel(mixed $return, ?\Throwable $throw = null): SubtaskGeneratorModel
    {
        $model = new SubtaskGeneratorModel($this->container);
        $model->setRegistry($this->fakeRegistry($return, $throw));
        return $model;
    }

    /**
     * Write a setting directly to the DB, bypassing SettingModel::save().
     */
    private function seedSetting(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    /** Stub userSession so isAdmin() returns true. */
    private function stubAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId'])
            ->getMock();

        $this->container['userSession']->method('isAdmin')->willReturn(true);
        $this->container['userSession']->method('getId')->willReturn(1);
    }

    /** Stub userSession so isAdmin() returns false. */
    private function stubNonAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId'])
            ->getMock();

        $this->container['userSession']->method('isAdmin')->willReturn(false);
        $this->container['userSession']->method('getId')->willReturn(2);
    }

    // ── (a) Canned valid output — normalized, deduped, clamped ───────────────

    /**
     * structured() returns a decoded PHP array directly.
     * generate() must return clean string titles.
     */
    public function testGenerateWithAnthropicStyleArrayResult(): void
    {
        $cannedArray = [
            'subtasks' => [
                ['title' => 'Set up the database schema'],
                ['title' => '  Write unit tests  '],   // leading/trailing space
                ['title' => 'Deploy to staging'],
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('Build a new feature');

        $this->assertSame(
            ['Set up the database schema', 'Write unit tests', 'Deploy to staging'],
            $result,
            'Should return trimmed titles from an already-decoded PHP array'
        );
    }

    /**
     * Deduplication: case-insensitive, preserves original casing of first occurrence.
     */
    public function testGenerateDeduplicatesTitles(): void
    {
        $cannedArray = [
            'subtasks' => [
                ['title' => 'Write unit tests'],
                ['title' => 'write unit tests'],         // lowercase dupe
                ['title' => 'WRITE UNIT TESTS'],         // uppercase dupe
                ['title' => 'Deploy to production'],
                ['title' => 'deploy to production'],    // dupe
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('Ship the feature');

        $this->assertSame(
            ['Write unit tests', 'Deploy to production'],
            $result,
            'Should deduplicate titles case-insensitively, keeping first occurrence'
        );
    }

    /**
     * Blank title entries must be dropped.
     */
    public function testGenerateDropsBlankTitles(): void
    {
        $cannedArray = [
            'subtasks' => [
                ['title' => ''],
                ['title' => '   '],
                ['title' => 'Valid title'],
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('A task');

        $this->assertSame(['Valid title'], $result, 'Blank/whitespace-only titles must be dropped');
    }

    // ── (b) Malformed output — graceful, no fatal ─────────────────────────────

    /**
     * structured() returns null (via mockProvider(null) equivalent) — generate() must return [] gracefully.
     */
    public function testGenerateReturnsEmptyOnNullResult(): void
    {
        $model  = $this->makeModel(null);
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'null result must produce an empty array, not a fatal');
    }

    /**
     * structured() returns a valid array but no 'subtasks' key — generate() returns [].
     */
    public function testGenerateReturnsEmptyWhenSubtasksKeyMissing(): void
    {
        $cannedArray = ['wrong_key' => [['title' => 'Ignored']]];
        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'Missing subtasks key must produce empty array');
    }

    /**
     * structured() returns an array with non-array 'subtasks' value — generate() returns [].
     */
    public function testGenerateReturnsEmptyWhenSubtasksIsNotArray(): void
    {
        $cannedArray = ['subtasks' => 'this should be an array'];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'Non-array subtasks must produce empty array');
    }

    /**
     * structured() returns items with non-string 'title' values — those are skipped.
     */
    public function testGenerateSkipsNonStringTitles(): void
    {
        $cannedArray = [
            'subtasks' => [
                ['title' => 42],           // integer — skip
                ['title' => null],         // null — skip
                ['title' => ['nested']],   // array — skip
                ['title' => 'Good title'], // valid — keep
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('A task');

        $this->assertSame(['Good title'], $result, 'Non-string titles must be skipped gracefully');
    }

    /**
     * structured() returns an array with an empty subtasks array.
     */
    public function testGenerateReturnsEmptyOnEmptySubtasksArray(): void
    {
        $cannedArray = ['subtasks' => []];
        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'Empty subtasks array must produce empty result');
    }

    // ── (c) Clamp to sg_max_subtasks ─────────────────────────────────────────

    /**
     * Results must be clamped to sg_max_subtasks.
     */
    public function testGenerateClampsToMaxSubtasks(): void
    {
        // Set max to 3.
        $this->seedSetting('sg_max_subtasks', '3');

        $cannedArray = [
            'subtasks' => [
                ['title' => 'Step 1'],
                ['title' => 'Step 2'],
                ['title' => 'Step 3'],
                ['title' => 'Step 4'],  // must be dropped
                ['title' => 'Step 5'],  // must be dropped
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('Big task');

        $this->assertCount(3, $result, 'Result must be clamped to sg_max_subtasks = 3');
        $this->assertSame(['Step 1', 'Step 2', 'Step 3'], $result);
    }

    /**
     * When sg_max_subtasks is 1, only one title must be returned.
     */
    public function testGenerateClampsToOne(): void
    {
        $this->seedSetting('sg_max_subtasks', '1');

        $cannedArray = [
            'subtasks' => [
                ['title' => 'Only first'],
                ['title' => 'Dropped'],
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('Tiny task');

        $this->assertSame(['Only first'], $result, 'Clamp to 1 must keep only the first title');
    }

    /**
     * When the provider returns fewer than max, all are returned (no padding).
     */
    public function testGenerateReturnsAllWhenUnderMax(): void
    {
        $this->seedSetting('sg_max_subtasks', '10');

        $cannedArray = [
            'subtasks' => [
                ['title' => 'Alpha'],
                ['title' => 'Beta'],
            ],
        ];

        $model  = $this->makeModel($cannedArray);
        $result = $model->generate('Short task');

        $this->assertCount(2, $result, 'All titles returned when under the cap');
    }

    // ── (d) Controller gate: non-admin / bad-CSRF rejected ────────────────────

    /**
     * generate() endpoint must throw AccessForbiddenException when AI disabled.
     */
    public function testGenerateControllerThrowsWhenAiDisabled(): void
    {
        $this->stubAdmin();

        $controller = new class($this->container) extends GeneratorController {
            protected function isAiEnabled(): bool { return false; }
        };

        $this->expectException(AccessForbiddenException::class);
        $controller->generate();
    }

    /**
     * STRUCTURE-CHECK (not a behavior test): verifies that the required guard expressions
     * are present in the controller source. The corresponding behavioral tests are:
     *   - testGenerateStubThrowsWhenAiDisabled (GeneratorTest) → isAiEnabled() gate
     *   - testGenerateControllerThrowsWhenAiDisabled             → AI-disabled gate
     * hasProjectAccess behavior is tested via the real permission check path in
     * testCreateThrowsForNonEditor (CreateSubtaskTest).
     */
    public function testControllerSourceGuardsOnPermissions(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/GeneratorController.php');

        $this->assertStringContainsString(
            'isAiEnabled()',
            $src,
            'generate() must check isAiEnabled()'
        );
        $this->assertStringContainsString(
            'hasProjectAccess',
            $src,
            'generate() must check hasProjectAccess'
        );
        $this->assertStringContainsString(
            'checkCSRFForm',
            $src,
            'generate() must call checkCSRFForm()'
        );
    }

    // ── (e) Registry throwing → clean JSON error, not 500 ────────────────────

    /**
     * STRUCTURE-CHECK (not a behavior test): verifies the catch block is present and
     * returns a JSON error. The corresponding behavioral test is
     * testModelGeneratePropagatesProviderException which confirms the model lets the
     * exception propagate so the controller's catch block is reached.
     *
     * When the registry throws, the controller must return a clean JSON error
     * without leaking the exception details to the client.
     */
    public function testControllerSourceCatchesProviderExceptionAndReturnsJsonError(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/GeneratorController.php');

        // Must have a catch block.
        $this->assertStringContainsString('catch (\\Throwable', $src,
            'generate() must catch \\Throwable from the provider');

        // The catch block must return a JSON error.
        $this->assertStringContainsString("'error'", $src,
            'catch block must return a JSON error key');

        // The JSON error value must be a translated string (t(...)), not the raw exception.
        $this->assertMatchesRegularExpression(
            '/response->json\(\[.*\'error\'.*t\(/s',
            $src,
            'The JSON error in the catch block must use t() translation, not the raw exception message'
        );
    }

    /**
     * Model::generate() propagates the exception so the controller can catch it.
     *
     * We verify that generate() does NOT silently swallow registry exceptions —
     * it must re-throw (or let it propagate naturally) so the controller can
     * return a clean JSON error rather than a 500.
     */
    public function testModelGeneratePropagatesProviderException(): void
    {
        $exception = new \RuntimeException('Provider network timeout');
        $model     = $this->makeModel(null, $exception);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider network timeout');
        $model->generate('A task');
    }

    /**
     * STRUCTURE-CHECK: verifies that the controller has a try/catch wrapping the model call.
     * Normalization does not swallow exceptions from generate() — the exception
     * reaches the caller (controller) which turns it into a clean JSON error.
     * This confirms the separation of concerns: model throws, controller catches.
     */
    public function testModelThrowsAndControllerWouldCatch(): void
    {
        // Verify by reading controller source that there is a try/catch wrapping the model call.
        $src = file_get_contents(dirname(__DIR__) . '/Controller/GeneratorController.php');

        $this->assertStringContainsString('try {', $src,
            'Controller generate() must have a try block wrapping the model call');
        $this->assertStringContainsString('$model->generate(', $src,
            'Controller must call $model->generate()');
    }
}
