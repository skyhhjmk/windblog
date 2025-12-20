<?php

namespace app\service\markdown\Extension\SBlock;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

class SBlockParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        $savedState = $cursor->saveState();
        $match = $cursor->match('/^::([a-z0-9\-]+)(?:\s+(.*))?$/i');

        if ($match === null) {
            $cursor->restoreState($savedState);

            return BlockStart::none();
        }

        // Check if it is an end block
        $captured = $cursor->getLine();
        if (trim($captured) === '::end') {
            return BlockStart::none(); // End block is handled by the block parser's tryContinue or by closing
        }

        // Parse type and params from the matched string manually if needed,
        // but $cursor->match returns the matched string, not groups.
        // Let's use preg_match on the line content for extraction.
        $line = $cursor->getLine();
        if (!preg_match('/^::([a-z0-9\-]+)(?:\s+(.*))?$/i', trim($line), $matches)) {
            $cursor->restoreState($savedState);

            return BlockStart::none();
        }

        $type = $matches[1];
        if ($type === 'end') {
            $cursor->restoreState($savedState);

            return BlockStart::none();
        }

        $paramsStr = $matches[2] ?? '';
        $params = $this->parseParams($paramsStr);

        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy(strlen($line)); // Consume the whole line

        return BlockStart::of(new SBlockParserContext(new SBlockNode($type, $params)))->at($cursor);
    }

    private function parseParams(string $paramsStr): array
    {
        $params = [];
        // Match key="value", key='value', key=value, or value
        if (preg_match_all('/([a-z0-9\-_]+)="([^"]*)"|([a-z0-9\-_]+)=\'([^\']*)\'|([a-z0-9\-_]+)=(\S+)|("[^"]*")|(\'[^\']*\')|(\S+)/i', $paramsStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!empty($match[1])) {
                    $params[$match[1]] = $match[2];
                } elseif (!empty($match[3])) {
                    $params[$match[3]] = $match[4];
                } elseif (!empty($match[5])) {
                    $params[$match[5]] = $match[6];
                } elseif (!empty($match[7])) {
                    // Positional quoted string "..."
                    $params[] = substr($match[7], 1, -1);
                } elseif (!empty($match[8])) {
                    // Positional single quoted '...'
                    $params[] = substr($match[8], 1, -1);
                } elseif (!empty($match[9])) {
                    // Positional unquoted
                    $params[] = $match[9];
                }
            }
        }

        return $params;
    }
}
