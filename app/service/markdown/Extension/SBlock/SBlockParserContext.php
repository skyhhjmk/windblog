<?php

namespace app\service\markdown\Extension\SBlock;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

class SBlockParserContext extends AbstractBlockContinueParser
{
    private SBlockNode $block;

    public function __construct(SBlockNode $block)
    {
        $this->block = $block;
    }

    public function getBlock(): AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isIndented()) {
            return BlockContinue::at($cursor);
        }

        $match = $cursor->match('/^::end\s*$/i');
        if ($match !== null) {
            return BlockContinue::finished();
        }

        return BlockContinue::at($cursor);
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }
}
