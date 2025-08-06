<?php
/**
 * Here is your custom functions.
 */

function get_blog_config(string $key, mixed $default = null, bool $set = false): mixed
{
    $cfg = app\model\Settings::where('key', $key)
        ->value('value');
    if ($cfg) {
        return $cfg;
    } else {
        if ($set) {
            app\model\Settings::updateOrInsert(
                ['key' => $key],
                ['value' => $default]
            );
        }
        return $default;
    }
}

function set_blog_config(string $key, mixed $value): bool
{
    try {
        app\model\Settings::updateOrInsert(
            ['key' => $key],
            ['value' => $value]
        );
    } catch (\Exception $e) {
        \support\Log::channel('system_function')->error('set_blog_config error: ' . $e->getMessage());
        return false;
    }
    return true;
}

/**
 * 将序列化的数据转为数组,并根据需要返回指定键的值[可选]
 * @param string $data_str
 * @param string|null $need
 * @return mixed
 */
function decodeData(string $data_str = '', ?string $need = null): mixed
{
    $decode_data = unserialize($data_str);
    if ($need !== null) {
        return $decode_data[$need] ?? null;
    } else {
        return $decode_data;
    }
}

/**
 * 将数组转为序列化数据
 * @param array $data_arr
 * @return string
 */
function encodeData(array $data_arr = []): string
{
    return serialize($data_arr);
}
