<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the OpenAI Responses API at the HTTP layer, like FakesAnthropic does for Claude.
 */
trait FakesOpenAi
{
    /** @var list<array<string, mixed>> */
    protected array $openAiRequests = [];

    /**
     * @param  list<list<array<string, mixed>>>  $turns  Output items built with openAiCall() and openAiText().
     */
    protected function fakeOpenAiTurns(array $turns): \Closure
    {
        $sequence = Http::sequence();

        foreach ($turns as $items) {
            $sequence->push([
                'id' => 'resp_'.str()->random(12),
                'object' => 'response',
                'status' => 'completed',
                'model' => 'gpt-6-luna',
                'output' => $items,
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'input_tokens_details' => ['cached_tokens' => 0]],
            ]);
        }

        return function (Request $request) use ($sequence) {
            $this->openAiRequests[] = $request->data();

            return $sequence($request);
        };
    }

    protected function openAiCall(string $id, string $name, array $arguments): array
    {
        return ['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => $id, 'name' => $name, 'arguments' => json_encode($arguments), 'status' => 'completed'];
    }

    protected function openAiText(string $text): array
    {
        return ['type' => 'message', 'id' => 'msg_'.str()->random(8), 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    }
}
