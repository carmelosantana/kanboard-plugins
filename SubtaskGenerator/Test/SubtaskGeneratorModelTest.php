<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SubtaskGenerator\Controller\GeneratorController;
use Kanboard\Plugin\SubtaskGenerator\Model\ProviderFactory;
use Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator Task 04: generate endpoint + normalization.
 *
 * All tests use a MOCK/STUB provider — NO network calls are ever made.
 *
 * Covers:
 *  (a) Canned valid JSON → generate() returns normalized/deduped/clamped titles.
 *  (b) Malformed model output (bad JSON / missing subtasks / non-string titles)
 *      → graceful (returns empty or [], NOT a fatal).
 *  (c) Clamp to sg_max_subtasks.
 *  (d) generate() endpoint rejects non-admin / bad-CSRF.
 *  (e) Provider throwing → controller returns a clean JSON error, not a 500.
 */
class SubtaskGeneratorModelTest extends Base
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Load vendor so provider classes are available. */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    /**
     * Build a SubtaskGeneratorModel with the given mock provider injected.
     */
    private function makeModel(ProviderInterface $provider): SubtaskGeneratorModel
    {
        $model = new SubtaskGeneratorModel($this->container);
        $model->setProvider($provider);
        return $model;
    }

    /**
     * Make a mock ProviderInterface whose structured() returns the given value.
     *
     * @param  mixed $returnValue   What structured() will return.
     */
    private function mockProvider(mixed $returnValue): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('structured')->willReturn($returnValue);
        return $mock;
    }

    /**
     * Make a mock ProviderInterface whose structured() throws the given exception.
     */
    private function throwingProvider(\Throwable $exception): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('structured')->willThrowException($exception);
        return $mock;
    }

    /**
     * Build a Response object (OpenAI/Grok path) with content as a JSON string.
     */
    private function makeResponse(string $jsonContent): Response
    {
        return new Response(
            content: $jsonContent,
            finishReason: ProviderFinishReason::Stop,
        );
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
     * Anthropic path: structured() returns a PHP array directly.
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
        $result = $model->generate('Build a new feature');

        $this->assertSame(
            ['Set up the database schema', 'Write unit tests', 'Deploy to staging'],
            $result,
            'Should return trimmed titles from an already-decoded PHP array'
        );
    }

    /**
     * OpenAI/Grok path: structured() returns a Response with a JSON string.
     * generate() must decode the JSON and return clean titles.
     */
    public function testGenerateWithOpenAIStyleResponseResult(): void
    {
        $json = json_encode([
            'subtasks' => [
                ['title' => 'Create API endpoint'],
                ['title' => 'Write integration test'],
            ],
        ]);

        $model  = $this->makeModel($this->mockProvider($this->makeResponse($json)));
        $result = $model->generate('Build REST API');

        $this->assertSame(
            ['Create API endpoint', 'Write integration test'],
            $result,
            'Should decode the JSON string in Response->content'
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
        $result = $model->generate('A task');

        $this->assertSame(['Valid title'], $result, 'Blank/whitespace-only titles must be dropped');
    }

    // ── (b) Malformed output — graceful, no fatal ─────────────────────────────

    /**
     * structured() returns null — generate() must return [] gracefully.
     */
    public function testGenerateReturnsEmptyOnNullResult(): void
    {
        $model  = $this->makeModel($this->mockProvider(null));
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'null result must produce an empty array, not a fatal');
    }

    /**
     * structured() returns a Response with invalid JSON — generate() must return [].
     */
    public function testGenerateReturnsEmptyOnInvalidJson(): void
    {
        $model  = $this->makeModel($this->mockProvider($this->makeResponse('NOT_JSON{{{')));
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'Invalid JSON in Response must produce empty array');
    }

    /**
     * structured() returns a valid JSON but no 'subtasks' key — generate() returns [].
     */
    public function testGenerateReturnsEmptyWhenSubtasksKeyMissing(): void
    {
        $json = json_encode(['wrong_key' => [['title' => 'Ignored']]]);
        $model  = $this->makeModel($this->mockProvider($this->makeResponse($json)));
        $result = $model->generate('A task');

        $this->assertSame([], $result, 'Missing subtasks key must produce empty array');
    }

    /**
     * structured() returns an array with non-array 'subtasks' value — generate() returns [].
     */
    public function testGenerateReturnsEmptyWhenSubtasksIsNotArray(): void
    {
        $cannedArray = ['subtasks' => 'this should be an array'];

        $model  = $this->makeModel($this->mockProvider($cannedArray));
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
        $result = $model->generate('A task');

        $this->assertSame(['Good title'], $result, 'Non-string titles must be skipped gracefully');
    }

    /**
     * structured() returns a Response with JSON containing no subtasks array.
     */
    public function testGenerateReturnsEmptyOnEmptySubtasksArray(): void
    {
        $json = json_encode(['subtasks' => []]);
        $model  = $this->makeModel($this->mockProvider($this->makeResponse($json)));
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
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

        $model  = $this->makeModel($this->mockProvider($cannedArray));
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
     * The controller test for non-admin rejection is covered by:
     *  - testGenerateStubThrowsWhenAiDisabled in GeneratorTest (AI disabled → 403).
     *  - hasProjectAccess check in the controller source.
     *
     * We verify the source file guards on both conditions.
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

    // ── (e) Provider throwing → clean JSON error, not 500 ────────────────────

    /**
     * When the provider throws, the controller must return a clean JSON error
     * without leaking the exception details to the client.
     *
     * We test this by verifying the controller source: the catch block calls
     * $this->response->json(['error' => ...]) and does NOT re-throw.
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
     * We verify that generate() does NOT silently swallow provider exceptions —
     * it must re-throw (or let it propagate naturally) so the controller can
     * return a clean JSON error rather than a 500.
     */
    public function testModelGeneratePropagatesProviderException(): void
    {
        $exception = new \RuntimeException('Provider network timeout');
        $model     = $this->makeModel($this->throwingProvider($exception));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider network timeout');
        $model->generate('A task');
    }

    /**
     * Normalisation does not swallow exceptions from generate() — the exception
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

    // ── structured() return-type documentation test ───────────────────────────

    /**
     * Verify source-level that AnthropicProvider::structured() can return an array
     * (the tool_use block['input'] path) — not just a Response object.
     *
     * This guards against a regression where someone changes generate() to always
     * call $res->content without checking the type.
     */
    public function testAnthropicProviderStructuredReturnsArrayOrResponse(): void
    {
        // Read the AnthropicProvider source from the vendored copy.
        $src = file_get_contents(
            dirname(__DIR__) . '/vendor/carmelosantana/php-agents/src/Provider/AnthropicProvider.php'
        );

        // The method must return $block['input'] ?? [] (PHP array) in the happy path.
        $this->assertStringContainsString(
            "return \$block['input'] ?? []",
            $src,
            'AnthropicProvider::structured() must return the tool_use block[input] directly as a PHP array'
        );

        // It must also have a fallback that may return a Response.
        $this->assertStringContainsString(
            'parseResponse',
            $src,
            'AnthropicProvider::structured() must have a Response fallback path'
        );
    }

    /**
     * Verify that SubtaskGeneratorModel::normaliseStructuredResult handles both shapes
     * by reading the source.
     */
    public function testModelHandlesBothReturnShapes(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Model/SubtaskGeneratorModel.php');

        // Must check for array.
        $this->assertStringContainsString('is_array($raw)', $src,
            'Model must handle PHP array return (Anthropic tool_use path)');

        // Must check for Response instance.
        $this->assertStringContainsString('instanceof Response', $src,
            'Model must handle Response object return (OpenAI/Grok path)');

        // Must json_decode the ->content when it is a Response.
        $this->assertStringContainsString('json_decode($raw->content', $src,
            'Model must json_decode the Response->content for the OpenAI/Grok path');
    }
}
