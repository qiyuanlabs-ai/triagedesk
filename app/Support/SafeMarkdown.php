<?php

namespace App\Support;

use Illuminate\Support\Str;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders the model's Markdown for display. Raw HTML and unsafe links are stripped, and an image
 * becomes its alt text: an image URL is fetched the moment a teammate opens the ticket, so a
 * prompt-injected image could carry data to another site without anyone clicking anything.
 */
class SafeMarkdown
{
    public static function render(string $markdown): string
    {
        return (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false], [
            new class implements ExtensionInterface, NodeRendererInterface
            {
                public function register(EnvironmentBuilderInterface $environment): void
                {
                    $environment->addRenderer(Image::class, $this, 100);
                }

                public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
                {
                    return $childRenderer->renderNodes($node->children());
                }
            },
        ]);
    }
}
