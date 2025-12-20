<?php

namespace app\service\markdown\Extension\SBlock;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

class SBlockRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        if (!($node instanceof SBlockNode)) {
            throw new \InvalidArgumentException('Incompatible node type: ' . get_class($node));
        }

        $type = $node->getType();
        $params = $node->getParams();

        $attrs = [
            'class' => 's-block s-' . $type,
        ];

        // Convert params to data attributes or classes if needed
        // Convert params to data attributes or classes if needed
        foreach ($params as $key => $value) {
            if (is_int($key)) {
                // Flag or positional arg
                // If value is a valid attribute suffix (alphanumeric/dash/underscore), treat as flag
                if (preg_match('/^[a-zA-Z0-9\-_]+$/', $value)) {
                    $attrs['data-' . $value] = '';
                } else {
                    // Otherwise store as generic arg to prevent invalid attribute names
                    $attrs['data-arg-' . $key] = $value;
                }
            } else {
                // Keyed param
                $attrs['data-' . $key] = $value;
            }
        }

        // Specific handling for grid columns
        if ($type === 'grid' && !empty($params)) {
            $cols = $params[0] ?? 2;
            $attrs['style'] = "--cols: {$cols}";
        }

        $tagName = 'div';
        $innerContent = $childRenderer->renderNodes($node->children());

        // Handle ::btn [Text](url)
        if ($type === 'btn') {
            // Check if we have a positional argument that looks like a markdown link [Text](url)
            foreach ($params as $key => $value) {
                // Check data-arg-key or just value
                if (is_int($key) && preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', trim($value), $matches)) {
                    $tagName = 'a';
                    $btnText = $matches[1];
                    $btnUrl = $matches[2];

                    $attrs['href'] = $btnUrl;

                    // Use param as content if inner content is empty
                    if (trim((string) $innerContent) === '') {
                        $innerContent = $btnText;
                    }

                    // Clean up attributes
                    unset($attrs['data-arg-' . $key]);
                    // Also remove if it was set as a flag (unlikely for complex string but safe to try)
                    unset($attrs['data-' . $value]);
                    break;
                }
            }
        }

        return new HtmlElement(
            $tagName,
            $attrs,
            $innerContent
        );
    }
}
