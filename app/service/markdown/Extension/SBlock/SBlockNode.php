<?php

namespace app\service\markdown\Extension\SBlock;

use League\CommonMark\Node\Block\AbstractBlock;

class SBlockNode extends AbstractBlock
{
    private string $type;

    private array $params;

    public function __construct(string $type, array $params = [])
    {
        $this->type = $type;
        $this->params = $params;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
