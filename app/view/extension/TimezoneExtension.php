<?php

namespace app\view\extension;

use Exception;
use Illuminate\Support\Carbon;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig时区转换扩展
 *
 * 将UTC时间自动转换为用户本地时间
 */
class TimezoneExtension extends AbstractExtension
{
    /**
     * 获取过滤器列表
     *
     * @return array
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('local_date', [$this, 'localDate']),
            new TwigFilter('local_time', [$this, 'localTime']),
            new TwigFilter('human_time', [$this, 'humanTime']),
        ];
    }

    /**
     * 将UTC时间转换为本地时间显示
     *
     * @param mixed       $date     UTC时间字符串或Carbon对象
     * @param string      $format   格式化模板
     * @param string|null $timezone 目标时区，默认使用系统配置
     *
     * @return string
     * @throws Throwable
     */
    public function localDate($date, string $format = 'Y-m-d H:i:s', ?string $timezone = null): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            // 获取目标时区（可从配置、用户设置或浏览器获取）
            $targetTimezone = $timezone ?? $this->getDefaultTimezone();

            // 解析UTC时间
            if ($date instanceof Carbon) {
                $carbon = $date->copy();
            } else {
                $carbon = Carbon::parse($date, 'UTC');
            }

            // 转换到目标时区
            $carbon->setTimezone($targetTimezone);

            return $carbon->format($format);
        } catch (Exception $e) {
            // 出错时返回原值
            return is_string($date) ? $date : '';
        }
    }

    /**
     * 仅转换时间部分（用于前端JS处理）
     *
     * 返回ISO 8601格式，让前端JavaScript自动处理本地化
     *
     * @param mixed $date UTC时间
     *
     * @return string
     */
    public function localTime($date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            if ($date instanceof Carbon) {
                $carbon = $date->copy();
            } else {
                $carbon = Carbon::parse($date, 'UTC');
            }

            // 返回ISO 8601格式，供JavaScript处理
            return $carbon->toIso8601String();
        } catch (Exception $e) {
            return is_string($date) ? $date : '';
        }
    }

    /**
     * 人性化时间显示（几分钟前、几小时前等）
     *
     * @param mixed       $date     UTC时间
     * @param string|null $timezone 目标时区
     *
     * @return string
     * @throws Throwable
     */
    public function humanTime($date, ?string $timezone = null): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            $targetTimezone = $timezone ?? $this->getDefaultTimezone();

            if ($date instanceof Carbon) {
                $carbon = $date->copy();
            } else {
                $carbon = Carbon::parse($date, 'UTC');
            }

            // 转换到目标时区后计算相对时间
            $carbon->setTimezone($targetTimezone);

            // 使用Carbon的diffForHumans方法（简体中文）
            return $carbon->locale('zh_CN')->diffForHumans();
        } catch (Exception $e) {
            return is_string($date) ? $date : '';
        }
    }

    /**
     * 获取默认时区
     *
     * 优先级：
     * 1. 用户设置的时区（可从session或cookie读取）
     * 2. 系统配置的时区
     * 3. 默认使用 Asia/Shanghai
     *
     * @return string
     * @throws Throwable
     */
    private function getDefaultTimezone(): string
    {
        // TODO: Refactor
        // 1. 尝试从用户设置读取（如果已实现）
        $session = request()?->session();
        if ($session && $userTimezone = $session->get('user_timezone')) {
            return $userTimezone;
        }

        // 2. 从配置读取
        $configTimezone = blog_config('user_timezone', 'Asia/Shanghai', true);
        if ($configTimezone && is_string($configTimezone)) {
            return $configTimezone;
        }

        // 3. 默认使用东八区（中国标准时间）
        return 'Asia/Shanghai';
    }
}
