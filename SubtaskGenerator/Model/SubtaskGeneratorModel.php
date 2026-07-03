<?php

namespace Kanboard\Plugin\SubtaskGenerator\Model;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use Kanboard\Core\Base;

/**
 * SubtaskGeneratorModel
 *
 * Calls the configured LLM provider's structured() with a simple
 * {subtasks:[{title}]} schema and returns a normalised/deduped/clamped
 * array of string titles.
 *
 * structured() return-type contract (verified in source):
 *  - AnthropicProvider: returns the tool_use block's $block['input']
 *    directly as an already-decoded PHP array, e.g. ['subtasks'=>[...]].
 *    Falls back to a Response object if no tool_use block is found.
 *  - OpenAICompatibleProvider / XAIProvider: calls chat() internally
 *    and returns a Response object whose ->content is a JSON string.
 *
 * This model handles both shapes via normaliseStructuredResult().
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Model
 * @author  Carmelo Santana
 */
class SubtaskGeneratorModel extends Base
{
    /** JSON schema passed to structured(). Name matches the tool name. */
    private const SCHEMA = [
        'name'   => 'subtasks',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'subtasks' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            'required' => ['subtasks'],
        ],
    ];

    /** System prompt sent with every structured() call. */
    private const SYSTEM_PROMPT = 'Break the task into concrete, actionable subtasks.';

    /**
     * Optional provider override — injected in tests; null → built from config.
     */
    private ?ProviderInterface $injectedProvider = null;

    /**
     * Inject a provider instance, bypassing ProviderFactory::buildFromConfig().
     *
     * Used in unit tests so no network call is ever made.
     */
    public function setProvider(ProviderInterface $provider): void
    {
        $this->injectedProvider = $provider;
    }

    /**
     * Generate candidate subtask titles for the given prompt.
     *
     * @param  string $prompt   Free-text prompt (task title + description).
     * @return string[]         Normalised, deduped, clamped array of titles.
     * @throws \RuntimeException on provider error (caller must catch).
     */
    public function generate(string $prompt): array
    {
        $provider = $this->injectedProvider
            ?? ProviderFactory::buildFromConfig($this->configModel);

        $messages = [
            new SystemMessage(self::SYSTEM_PROMPT),
            new UserMessage($prompt),
        ];

        $schema = json_encode(self::SCHEMA);

        // structured() returns mixed — see class docblock for the two shapes.
        $raw = $provider->structured($messages, $schema);

        $decoded = $this->normaliseStructuredResult($raw);

        return $this->normalise($decoded);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Decode the raw structured() return value to a plain PHP array.
     *
     * Handles two shapes:
     *  1. Already a PHP array (AnthropicProvider tool_use path) — use as-is.
     *  2. A Response object whose ->content is a JSON string (OpenAI/Grok path).
     *
     * Any other shape (null, unexpected object…) returns [].
     *
     * @param  mixed $raw
     * @return array<string, mixed>
     */
    private function normaliseStructuredResult(mixed $raw): array
    {
        // Shape 1: AnthropicProvider returns the tool_use block['input'] directly
        // as an already-decoded PHP array — e.g. ['subtasks' => [...]].
        if (is_array($raw)) {
            return $raw;
        }

        // Shape 2: OpenAI / Grok providers call chat() internally and return a
        // Response object whose ->content property holds a JSON string.
        if ($raw instanceof Response) {
            $decoded = json_decode($raw->content, true);
            return is_array($decoded) ? $decoded : [];
        }

        // Unknown shape — return empty so the caller gets an empty list rather
        // than a fatal.
        return [];
    }

    /**
     * Validate and normalise the decoded array into clean string titles.
     *
     * Steps:
     *  1. Extract subtasks array.
     *  2. Collect non-empty string titles (trim; skip blank; skip non-string).
     *  3. Deduplicate (case-insensitive, preserving original casing of first seen).
     *  4. Clamp to sg_max_subtasks.
     *
     * @param  array<string, mixed> $data
     * @return string[]
     */
    private function normalise(array $data): array
    {
        $rawItems = $data['subtasks'] ?? [];

        if (! is_array($rawItems)) {
            return [];
        }

        $max = (int) $this->configModel->get(
            'sg_max_subtasks',
            (string) ProviderFactory::DEFAULT_MAX_SUBTASKS
        );
        if ($max < 1) {
            $max = ProviderFactory::DEFAULT_MAX_SUBTASKS;
        }

        $titles = [];
        $seen   = [];          // lowercase → dedupe index

        foreach ($rawItems as $item) {
            // Each item should be ['title' => '...'] but be defensive.
            if (! is_array($item)) {
                // Some providers may return a flat list of strings.
                if (is_string($item)) {
                    $title = trim($item);
                    if ($title === '') {
                        continue;
                    }
                    $lower = mb_strtolower($title);
                    if (isset($seen[$lower])) {
                        continue;
                    }
                    $seen[$lower] = true;
                    $titles[]     = $title;
                }
                continue;
            }

            $raw = $item['title'] ?? null;
            if (! is_string($raw)) {
                continue;
            }

            $title = trim($raw);
            if ($title === '') {
                continue;
            }

            $lower = mb_strtolower($title);
            if (isset($seen[$lower])) {
                continue;                    // dedupe
            }

            $seen[$lower] = true;
            $titles[]     = $title;
        }

        return array_slice($titles, 0, $max);
    }
}
