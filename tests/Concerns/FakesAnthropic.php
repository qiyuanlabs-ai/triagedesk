<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Anthropic Messages API at the HTTP layer, so the SDK's real tool loop,
 * approval pauses and resumes all run offline and for free.
 */
trait FakesAnthropic
{
    /** @var list<array<string, mixed>> */
    protected array $anthropicRequests = [];

    /**
     * Queue model turns. Each turn is a list of content blocks built with toolUse() and text().
     *
     * @param  list<list<array<string, mixed>>>  $turns
     */
    protected function fakeAnthropic(array $turns): void
    {
        $sequence = Http::sequence();

        foreach ($turns as $blocks) {
            $usesTool = collect($blocks)->contains('type', 'tool_use');

            $sequence->push([
                'id' => 'msg_'.str()->random(12),
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-test',
                'content' => $blocks,
                'stop_reason' => $usesTool ? 'tool_use' : 'end_turn',
                'stop_sequence' => null,
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ]);
        }

        Http::fake(['api.anthropic.com/*' => function (Request $request) use ($sequence) {
            $this->anthropicRequests[] = $request->data();

            return $sequence($request);
        }]);
    }

    protected function toolUse(string $id, string $name, array $input): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
    }

    protected function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * Find the tool result the SDK sent back to the model for a tool call.
     */
    protected function toolResultSent(string $toolUseId): ?string
    {
        foreach (array_reverse($this->anthropicRequests) as $request) {
            foreach ($request['messages'] ?? [] as $message) {
                foreach ((array) ($message['content'] ?? []) as $block) {
                    if (is_array($block) && ($block['type'] ?? null) === 'tool_result' && $block['tool_use_id'] === $toolUseId) {
                        return is_array($block['content']) ? json_encode($block['content']) : (string) $block['content'];
                    }
                }
            }
        }

        return null;
    }
}
