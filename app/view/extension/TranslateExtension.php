<?php

namespace app\view\extension;

use Exception;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Class TranslateExtension
 * @package app\view\extension
 */
class TranslateExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('trans', [$this, 'getTranslate']),
        ];
    }

    /**
     * @throws Exception
     */
    public function getTranslate(string $key,?string $lang = null, ?array $params = []): string
    {
        if ($lang === null) {
            $lang = session('lang', 'zh_CN');
        }
        return trans($key, $params, null, $lang);
    }
}