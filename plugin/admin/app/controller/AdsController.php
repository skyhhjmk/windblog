<?php

namespace plugin\admin\app\controller;

use app\model\Ad;
use app\model\Setting;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 广告管理（自定义页面，不复用通用表单引擎）
 */
class AdsController extends Base
{
    /**
     * 列表页
     */
    public function index(Request $request): Response
    {
        return view('ads/index');
    }

    /**
     * 列表数据
     */
    public function list(Request $request): Response
    {
        $title = (string) $request->get('title', '');
        $type = (string) $request->get('type', '');
        $enabled = $request->get('enabled', '');
        $isTrashed = (string) $request->get('isTrashed', 'false');
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 15);
        $order = (string) $request->get('order', 'id');
        $sort = (string) $request->get('sort', 'desc');

        $page = $page > 0 ? $page : 1;
        $limit = $limit > 0 ? $limit : 15;

        if ($isTrashed === 'true') {
            $query = Ad::withoutGlobalScope('notDeleted')->whereNotNull('deleted_at');
        } else {
            $query = Ad::query();
        }

        if ($title !== '') {
            $query->where('title', 'like', "%{$title}%");
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($enabled !== '' && ($enabled === '0' || $enabled === '1' || $enabled === 0 || $enabled === 1)) {
            $query->where('enabled', (int) $enabled === 1);
        }

        $total = $query->count();
        $list = $query->orderBy($order, $sort)
            ->forPage($page, $limit)
            ->get()
            ->toArray();

        return $this->success('成功', $list, $total);
    }

    /**
     * 获取广告详情（AJAX）
     */
    public function get(Request $request, int $id): Response
    {
        $ad = Ad::withoutGlobalScope('notDeleted')->find($id);
        if (!$ad) {
            return $this->fail('广告不存在');
        }
        $data = $ad->toArray();
        $data['placements_text'] = $ad->placements ? json_encode($ad->placements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return $this->success('成功', $data);
    }

    /**
     * 新增页（GET）/ 提交（POST）
     */
    public function add(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $data = $request->post();
            [$ok, $msg] = $this->validateData($data);
            if (!$ok) {
                return $this->fail($msg);
            }

            try {
                $ad = new Ad();
                $this->fillModel($ad, $data);
                $ad->save();

                return $this->success('创建成功', ['id' => $ad->id]);
            } catch (Throwable $e) {
                Log::error('[AdsController.add] ' . $e->getMessage());

                return $this->fail('创建失败');
            }
        }

        return view('ads/add');
    }

    /**
     * 校验
     */
    protected function validateData(array $data, bool $isCreate = true): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $type = (string) ($data['type'] ?? 'image');
        if ($title === '') {
            return [false, '标题不能为空'];
        }
        if (!in_array($type, ['image', 'google', 'html'], true)) {
            return [false, '不支持的广告类型'];
        }
        if ($type === 'image' && empty($data['image_url'])) {
            return [false, '图片广告必须提供图片地址'];
        }
        if ($type === 'google' && (empty($data['google_ad_client']) || empty($data['google_ad_slot']))) {
            return [false, 'Google广告必须提供 Ad Client 与 Ad Slot'];
        }

        return [true, ''];
    }

    /**
     * 填充模型
     */
    protected function fillModel(Ad $ad, array $data): void
    {
        $ad->title = trim((string) ($data['title'] ?? ''));
        $ad->type = (string) ($data['type'] ?? 'image');
        $ad->enabled = (int) ($data['enabled'] ?? 1) === 1;
        $ad->image_url = $data['image_url'] ?? null;
        $ad->link_url = $data['link_url'] ?? null;
        $ad->link_target = $data['link_target'] ?? '_blank';
        $ad->html = $data['html'] ?? null;
        $ad->google_ad_client = $data['google_ad_client'] ?? null;
        $ad->google_ad_slot = $data['google_ad_slot'] ?? null;
        $placements_text = $data['placements'] ?? ($data['placements_text'] ?? '');
        if (is_array($placements_text)) {
            $ad->placements = $placements_text;
        } else {
            $trim = trim((string) $placements_text);
            if ($trim !== '') {
                try {
                    $json = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
                    $ad->placements = $json;
                } catch (Exception $e) {
                    // 解析失败则忽略
                    $ad->placements = null;
                }
            } else {
                $ad->placements = null;
            }
        }
        $ad->weight = (int) ($data['weight'] ?? 100);
    }

    /**
     * 编辑页（GET）/ 提交（POST）
     */
    public function edit(Request $request, int $id): Response
    {
        $ad = Ad::withoutGlobalScope('notDeleted')->find($id);
        if (!$ad) {
            return $this->fail('广告不存在');
        }

        if ($request->method() === 'POST') {
            $data = $request->post();
            [$ok, $msg] = $this->validateData($data, false);
            if (!$ok) {
                return $this->fail($msg);
            }
            try {
                $this->fillModel($ad, $data);
                $ad->save();

                return $this->success('更新成功');
            } catch (Throwable $e) {
                Log::error('[AdsController.edit] ' . $e->getMessage());

                return $this->fail('更新失败');
            }
        }

        // 前端通过 AJAX 获取详情并渲染，页面无需模板变量
        return view('ads/edit');
    }

    /**
     * 软删除
     */
    public function remove(Request $request, int $id): Response
    {
        $ad = Ad::query()->find($id);
        if (!$ad) {
            return $this->fail('广告不存在');
        }
        try {
            $ad->deleted_at = utc_now_string('Y-m-d H:i:s');
            $ad->save();

            return $this->success('已删除');
        } catch (Throwable $e) {
            Log::error('[AdsController.remove] ' . $e->getMessage());

            return $this->fail('删除失败');
        }
    }

    /**
     * 恢复
     */
    public function restore(Request $request, int $id): Response
    {
        $ad = Ad::withoutGlobalScope('notDeleted')->find($id);
        if (!$ad) {
            return $this->fail('广告不存在');
        }
        try {
            $ad->deleted_at = null;
            $ad->save();

            return $this->success('已恢复');
        } catch (Throwable $e) {
            Log::error('[AdsController.restore] ' . $e->getMessage());

            return $this->fail('恢复失败');
        }
    }

    /**
     * 启用/禁用
     */
    public function toggleEnabled(Request $request, int $id): Response
    {
        $ad = Ad::withoutGlobalScope('notDeleted')->find($id);
        if (!$ad) {
            return $this->fail('广告不存在');
        }
        $value = $request->post('value');
        try {
            if ($value === null) {
                $ad->enabled = !$ad->enabled;
            } else {
                $ad->enabled = (int) $value === 1;
            }
            $ad->save();

            return $this->success('已更新');
        } catch (Throwable $e) {
            Log::error('[AdsController.toggleEnabled] ' . $e->getMessage());

            return $this->fail('更新失败');
        }
    }

    /**
     * 全局 Google AdSense 配置页
     */
    public function config(Request $request): Response
    {
        return view('ads/config');
    }

    /**
     * 获取全局 Google AdSense 配置
     */
    public function getConfig(Request $request): Response
    {
        $row = Setting::query()->where('key', 'google_adsense')->value('value');
        $data = [
            'client' => '',
            'auto_ads' => false,
        ];
        if (is_string($row) && $row !== '') {
            try {
                $cfg = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($cfg)) {
                    $data['client'] = (string) ($cfg['client'] ?? '');
                    $data['auto_ads'] = (bool) ($cfg['auto_ads'] ?? false);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $this->success('成功', $data);
    }

    /**
     * 保存全局 Google AdSense 配置
     */
    public function saveConfig(Request $request): Response
    {
        $client = trim((string) $request->post('client', ''));
        $auto = (int) $request->post('auto_ads', 0) === 1;
        if ($client !== '' && !preg_match('/^ca-pub-\d{8,}$/', $client)) {
            return $this->fail('Ad Client 格式不正确');
        }
        try {
            $s = Setting::firstOrNew(['key' => 'google_adsense']);
            $s->value = json_encode(['client' => $client, 'auto_ads' => $auto], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $s->save();

            return $this->success('保存成功');
        } catch (\Throwable $e) {
            Log::error('[AdsController.saveConfig] ' . $e->getMessage());

            return $this->fail('保存失败');
        }
    }
}
