<?php

namespace App\Ai\Tools;

use App\Models\KbArticle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBase implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search the help center for articles to base a reply on. Returns the 3 best matches in full.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:200']]);

        $articles = KbArticle::search($data['query']);

        if ($articles === []) {
            return 'No articles matched. Do not invent policy; say you will check with the team.';
        }

        return collect($articles)
            ->map(fn (KbArticle $article) => "## {$article->title} (article {$article->id})\n{$article->body}")
            ->implode("\n\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }
}
