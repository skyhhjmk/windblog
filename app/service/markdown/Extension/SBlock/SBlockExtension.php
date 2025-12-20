<?php

namespace app\service\markdown\Extension\SBlock;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class SBlockExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addBlockStartParser(new SBlockParser());
        $environment->addRenderer(SBlockNode::class, new SBlockRenderer());
    }
}
