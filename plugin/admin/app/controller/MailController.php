<?php
declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\service\MailService;
use support\Request;
use support\Response;

/**
 * 后台-邮件管理（占位接口/入口）
 */
class MailController
{
    /**
     * 获取/保存配置所用的键名清单
     */
    protected function mailConfigKeys(): array
    {
        return [
            'mail_transport', 'mail_host', 'mail_port',
            'mail_username', 'mail_password', 'mail_encryption',
            'mail_from_address', 'mail_from_name', 'mail_reply_to',
            // 队列相关也可在此查看（只读）以便页面展示
            'rabbitmq_mail_exchange', 'rabbitmq_mail_routing_key', 'rabbitmq_mail_queue',
            'rabbitmq_mail_dlx_exchange', 'rabbitmq_mail_dlx_queue',
        ];
    }

    /**
     * GET /app/admin/mail/config
     * 返回当前邮件相关配置
     */
    public function configGet(Request $request): Response
    {
        $keys = $this->mailConfigKeys();
        $data = [];
        foreach ($keys as $k) {
            // 读取时不强初始化写库，use_cache=true
            $data[$k] = blog_config($k, '', false, true, false);
        }
        // 不回显密码原文：如果存在，则用占位符
        if (!empty($data['mail_password'])) {
            $data['mail_password'] = '******';
        }
        return json(['code' => 0, 'data' => $data]);
    }

    /**
     * POST /app/admin/mail/config
     * 保存邮件配置，blog_config 写入
     * body: { mail_transport, mail_host, mail_port, mail_username, mail_password?, mail_encryption, mail_from_address, mail_from_name, mail_reply_to }
     */
    public function configSave(Request $request): Response
    {
        try {
            $post = (array)$request->post();

            $transport = (string)($post['mail_transport'] ?? 'smtp');
            $host      = (string)($post['mail_host'] ?? '');
            $port      = (int)($post['mail_port'] ?? 587);
            $username  = (string)($post['mail_username'] ?? '');
            $password  = $post['mail_password'] ?? null; // 允许不传或传占位
            $enc       = (string)($post['mail_encryption'] ?? 'tls'); // tls/ssl/none
            $fromAddr  = (string)($post['mail_from_address'] ?? '');
            $fromName  = (string)($post['mail_from_name'] ?? '');
            $replyTo   = (string)($post['mail_reply_to'] ?? '');

            // 简单校验
            if ($host === '' || $fromAddr === '') {
                return json(['code' => 1, 'msg' => 'mail_host 与 mail_from_address 不能为空']);
            }
            if (!in_array($transport, ['smtp'], true)) {
                return json(['code' => 1, 'msg' => '暂仅支持 smtp']);
            }

            // 保存（使用 blog_config 写入）
            blog_config('mail_transport', $transport, false, true, true);
            blog_config('mail_host', $host, false, true, true);
            blog_config('mail_port', $port, false, true, true);
            blog_config('mail_username', $username, false, true, true);
            if ($password !== null && $password !== '******') {
                blog_config('mail_password', (string)$password, false, true, true);
            }
            blog_config('mail_encryption', $enc, false, true, true);
            blog_config('mail_from_address', $fromAddr, false, true, true);
            blog_config('mail_from_name', $fromName, false, true, true);
            blog_config('mail_reply_to', $replyTo, false, true, true);

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 邮件设置页（多Tab入口：基础配置/模板预览/发信测试/队列监控）
     * GET /app/admin/mail/index
     */
    public function index(Request $request): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>邮件设置</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/layui@2.8.18/dist/css/layui.css" />
</head>
<body class="layui-container" style="padding:16px;">
  <fieldset class="layui-elem-field">
    <legend>邮件</legend>
    <div class="layui-field-box">
      <div class="layui-tab" lay-filter="mailTab">
        <ul class="layui-tab-title">
          <li class="layui-this">基础配置</li>
          <li>模板预览</li>
          <li>发信测试</li>
          <li>队列监控</li>
        </ul>
        <div class="layui-tab-content" style="padding:16px;">
          <!-- 基础配置 -->
          <div class="layui-tab-item layui-show">
            <form class="layui-form" id="cfgForm" lay-filter="cfgForm" onsubmit="return false;">
              <div class="layui-form-item">
                <label class="layui-form-label">传输</label>
                <div class="layui-input-block">
                  <select name="mail_transport">
                    <option value="smtp">smtp</option>
                  </select>
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">主机</label>
                <div class="layui-input-block">
                  <input type="text" name="mail_host" required lay-verify="required" placeholder="SMTP 服务器" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">端口</label>
                <div class="layui-input-block">
                  <input type="number" name="mail_port" placeholder="默认587" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">用户名</label>
                <div class="layui-input-block">
                  <input type="text" name="mail_username" placeholder="可为空" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">密码</label>
                <div class="layui-input-block">
                  <input type="password" name="mail_password" placeholder="不修改留空" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">加密</label>
                <div class="layui-input-block">
                  <select name="mail_encryption">
                    <option value="tls">tls</option>
                    <option value="ssl">ssl</option>
                    <option value="none">none</option>
                  </select>
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">发件地址</label>
                <div class="layui-input-block">
                  <input type="text" name="mail_from_address" required lay-verify="required" placeholder="noreply@example.com" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">发件名称</label>
                <div class="layui-input-block">
                  <input type="text" name="mail_from_name" placeholder="显示名称" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">Reply-To</label>
                <div class="layui-input-block">
                  <input type="text" name="mail_reply_to" placeholder="可选" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <div class="layui-input-block">
                  <button class="layui-btn" lay-submit lay-filter="doSaveCfg">保存</button>
                  <button class="layui-btn layui-btn-normal" type="button" id="btnTestConn">测试连接</button>
                  <span id="cfgMsg" style="margin-left:8px;color:#888;"></span>
                </div>
              </div>
            </form>
            <fieldset class="layui-elem-field">
              <legend>队列键名（只读）</legend>
              <div class="layui-field-box">
                <pre id="mqKeys" class="layui-code">加载中...</pre>
              </div>
            </fieldset>
          </div>

          <!-- 模板预览 -->
          <div class="layui-tab-item">
            <form class="layui-form" id="previewForm" lay-filter="previewForm" onsubmit="return false;">
              <div class="layui-form-item">
                <label class="layui-form-label">模板名</label>
                <div class="layui-input-block">
                  <input type="text" name="view" required lay-verify="required" placeholder="如：emails/example" autocomplete="off" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">变量(JSON)</label>
                <div class="layui-input-block">
                  <textarea name="vars" placeholder='如：{"username":"Alice"}' class="layui-textarea" style="height:100px;"></textarea>
                </div>
              </div>
              <div class="layui-form-item">
                <div class="layui-input-block">
                  <button class="layui-btn" lay-submit lay-filter="doPreview">预览</button>
                </div>
              </div>
            </form>
            <iframe id="previewFrame" style="width:100%;min-height:480px;border:1px solid #eee;"></iframe>
          </div>

          <!-- 发信测试 -->
          <div class="layui-tab-item">
            <form class="layui-form" id="sendForm" lay-filter="sendForm" onsubmit="return false;">
              <div class="layui-form-item">
                <label class="layui-form-label">收件人</label>
                <div class="layui-input-block">
                  <input type="text" name="to" required lay-verify="required" placeholder="test@example.com, 或 JSON 数组" autocomplete="off" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">主题</label>
                <div class="layui-input-block">
                  <input type="text" name="subject" required lay-verify="required" placeholder="邮件主题" autocomplete="off" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">模板名</label>
                <div class="layui-input-block">
                  <input type="text" name="view" placeholder="如：emails/example（可留空）" autocomplete="off" class="layui-input">
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">模板变量</label>
                <div class="layui-input-block">
                  <textarea name="view_vars" placeholder='如：{"name":"Alice"}' class="layui-textarea" style="height:100px;"></textarea>
                </div>
              </div>
              <div class="layui-form-item">
                <label class="layui-form-label">纯文本</label>
                <div class="layui-input-block">
                  <textarea name="text" placeholder="可选：纯文本正文（HTML不填时生效）" class="layui-textarea" style="height:80px;"></textarea>
                </div>
              </div>
              <div class="layui-form-item">
                <div class="layui-input-block">
                  <button class="layui-btn" lay-submit lay-filter="doSend">发信测试</button>
                </div>
              </div>
            </form>
            <pre id="sendResult" class="layui-code"></pre>
          </div>

          <!-- 队列监控 -->
          <div class="layui-tab-item">
            <div style="margin-bottom:12px;">
              <button class="layui-btn layui-btn-primary" id="btnRefresh">刷新</button>
              <span id="lastTime" style="margin-left:8px;color:#888;"></span>
            </div>
            <table class="layui-table">
              <colgroup>
                <col width="180"><col>
              </colgroup>
              <tbody id="queueTable">
                <tr><td>exchange</td><td>-</td></tr>
                <tr><td>routingKey</td><td>-</td></tr>
                <tr><td>queue</td><td>-</td></tr>
                <tr><td>queue_depth</td><td>-</td></tr>
                <tr><td>dlx</td><td>-</td></tr>
                <tr><td>dlq</td><td>-</td></tr>
                <tr><td>dlq_depth</td><td>-</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </fieldset>

  <script src="https://cdn.jsdelivr.net/npm/layui@2.8.18/dist/layui.js"></script>
  <script>
  layui.use(['form','element'], function(){
    const form = layui.form;

    // 加载配置
    (async function loadCfg(){
      try{
        const r = await fetch('/app/admin/mail/config');
        const ret = await r.json();
        if(ret && ret.code === 0 && ret.data){
          form.val('cfgForm', ret.data);
          // 显示只读的队列键名
          const keys = {
            rabbitmq_mail_exchange: ret.data.rabbitmq_mail_exchange,
            rabbitmq_mail_routing_key: ret.data.rabbitmq_mail_routing_key,
            rabbitmq_mail_queue: ret.data.rabbitmq_mail_queue,
            rabbitmq_mail_dlx_exchange: ret.data.rabbitmq_mail_dlx_exchange,
            rabbitmq_mail_dlx_queue: ret.data.rabbitmq_mail_dlx_queue
          };
          document.getElementById('mqKeys').textContent = JSON.stringify(keys, null, 2);
        }
      }catch(e){
        document.getElementById('mqKeys').textContent = '加载失败';
      }
    })();

    // 保存配置
    form.on('submit(doSaveCfg)', async function(data){
      const body = data.field;
      // 若密码为空则不更新，后端会忽略
      if(!body.mail_password){ delete body.mail_password; }
      const resp = await fetch('/app/admin/mail/config-save', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
      });
      const ret = await resp.json();
      document.getElementById('cfgMsg').textContent = ret.code===0 ? '已保存' : ('保存失败：' + (ret.msg||'')); 
      layer.msg(ret.code===0 ? '保存成功' : ('保存失败：' + (ret.msg||'')));
      return false;
    });

    // 测试连接
    document.getElementById('btnTestConn').addEventListener('click', async function(){
      const data = layui.form.val('cfgForm') || {};
      if(!data.mail_password){ delete data.mail_password; }
      const resp = await fetch('/app/admin/mail/config-test', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
      });
      const ret = await resp.json();
      layer.msg(ret.code===0 ? ('连接成功：' + (ret.msg||'')) : ('连接失败：' + (ret.msg||'')));
    });

    // 预览
    form.on('submit(doPreview)', function(data){
      const view = (data.field.view || '').trim();
      if(!view){ return false; }
      let vars = {};
      if(data.field.vars){
        try { vars = JSON.parse(data.field.vars); } catch(e){ layer.msg('变量JSON格式错误'); return false; }
      }
      const q = new URLSearchParams();
      q.set('view', view);
      Object.keys(vars).forEach(k=>{
        q.set('vars['+k+']', vars[k]);
      });
      const url = '/app/admin/mail/preview?' + q.toString();
      document.getElementById('previewFrame').src = url;
      return false;
    });

    // 发信测试
    form.on('submit(doSend)', async function(data){
      const body = {};
      // to 支持字符串或JSON数组
      const toRaw = (data.field.to||'').trim();
      try { body.to = JSON.parse(toRaw); } catch(e) { body.to = toRaw; }
      body.subject = (data.field.subject||'').trim();
      if(data.field.view){ body.view = (data.field.view||'').trim(); }
      if(data.field.view_vars){
        try { body.view_vars = JSON.parse(data.field.view_vars); } catch(e){ layer.msg('模板变量JSON格式错误'); return false; }
      }
      if(data.field.text){ body.text = data.field.text; }

      const resp = await fetch('/app/admin/mail/enqueue-test', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
      });
      const ret = await resp.json();
      document.getElementById('sendResult').textContent = JSON.stringify(ret, null, 2);
      layer.msg(ret.code===0 ? '已入队' : ('失败: '+(ret.msg||'')));
      return false;
    });

    async function loadStats(){
      try{
        const r = await fetch('/app/admin/mail/queue-stats');
        const d = await r.json();
        if(d && d.data){
          const map = d.data;
          const el = document.getElementById('queueTable');
          el.innerHTML = '';
          const rows = [
            ['exchange', map.exchange],
            ['routingKey', map.routingKey],
            ['queue', map.queue],
            ['queue_depth', map.queue_depth],
            ['dlx', map.dlx],
            ['dlq', map.dlq],
            ['dlq_depth', map.dlq_depth],
          ];
          rows.forEach(([k,v])=>{
            const tr = document.createElement('tr');
            const td1 = document.createElement('td'); td1.textContent = k;
            const td2 = document.createElement('td'); td2.textContent = (v===undefined||v===null)?'-':String(v);
            tr.appendChild(td1); tr.appendChild(td2);
            el.appendChild(tr);
          });
          document.getElementById('lastTime').textContent = 'Last update: ' + new Date().toLocaleString();
        }
      }catch(e){
        layer.msg('获取队列信息失败');
      }
    }
    document.getElementById('btnRefresh').addEventListener('click', loadStats);
    // 初次进入时加载一次
    loadStats();
  });
  </script>
</body>
</html>
HTML;
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * 队列监控页
     * GET /app/admin/mail/queue
     */
    public function queue(Request $request): Response
    {
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><title>队列监控</title></head>
<body style="padding:16px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial">
<h2>邮件队列监控</h2>
<p>本页可通过 Ajax 调用 GET /app/admin/mail/queue-stats 获取队列深度，周期刷新展示。</p>
<pre id="stats">加载中...</pre>
<script>
fetch('/app/admin/mail/queue-stats').then(r=>r.json()).then(d=>{
  document.getElementById('stats').textContent = JSON.stringify(d, null, 2);
}).catch(e=>{document.getElementById('stats').textContent=String(e);});
</script>
</body></html>
HTML;
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * 预览模板渲染（仅渲染返回HTML，不发送）
     * GET /app/admin/mail/preview?view=emails/example&vars[username]=Alice
     */
    public function templatesPreview(Request $request): Response
    {
        $view = (string)$request->get('view', '');
        if ($view === '') {
            return json(['code' => 1, 'msg' => 'view is required']);
        }
        $vars = (array)$request->get('vars', []);
        try {
            $html = MailService::renderView($view, $vars, app: null, plugin: null);
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 队列统计占位（不直接访问MQ，返回键名供前端展示）
     * GET /admin/mail/queue-stats
     */
    public function queueStats(): Response
    {
        try {
            $exchange   = (string)blog_config('rabbitmq_mail_exchange', 'mail_exchange', true);
            $routingKey = (string)blog_config('rabbitmq_mail_routing_key', 'mail_send', true);
            $queue      = (string)blog_config('rabbitmq_mail_queue', 'mail_queue', true);
            $dlx        = (string)blog_config('rabbitmq_mail_dlx_exchange', 'mail_dlx_exchange', true);
            $dlq        = (string)blog_config('rabbitmq_mail_dlx_queue', 'mail_dlx_queue', true);

            $host   = (string)blog_config('rabbitmq_host', '127.0.0.1', true);
            $port   = (int)blog_config('rabbitmq_port', 5672, true);
            $user   = (string)blog_config('rabbitmq_user', 'guest', true);
            $pass   = (string)blog_config('rabbitmq_password', 'guest', true);
            $vhost  = (string)blog_config('rabbitmq_vhost', '/', true);

            $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $pass, $vhost);
            $ch = $conn->channel();

            // 被动声明获取队列深度（返回[queue, messageCount, consumerCount]）
            [$qName, $qCount] = (function() use ($ch, $queue) {
                try {
                    $result = $ch->queue_declare($queue, true, true, false, false);
                    return [$result[0] ?? $queue, (int)($result[1] ?? 0)];
                } catch (\Throwable $e) {
                    return [$queue, 0];
                }
            })();

            [$dlqName, $dlqCount] = (function() use ($ch, $dlq) {
                try {
                    $result = $ch->queue_declare($dlq, true, true, false, false);
                    return [$result[0] ?? $dlq, (int)($result[1] ?? 0)];
                } catch (\Throwable $e) {
                    return [$dlq, 0];
                }
            })();

            $ch->close();
            $conn->close();

            return json([
                'code' => 0,
                'data' => [
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                    'queue' => $qName,
                    'queue_depth' => $qCount,
                    'dlx' => $dlx,
                    'dlq' => $dlqName,
                    'dlq_depth' => $dlqCount,
                ]
            ]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 入队一封测试邮件（便于后台联通性检查）
     * POST /admin/mail/enqueue-test
     * body: { "to": "test@example.com", "subject": "Test", "view": "emails/example", "view_vars": {"name":"Alice"} }
     */
    public function enqueueTest(Request $request): Response
    {
        $data = (array)$request->post();
        if (empty($data['to'])) {
            return json(['code' => 1, 'msg' => 'to is required']);
        }
        $ok = MailService::enqueue($data);
        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'enqueued' : 'failed']);
    }

    /**
     * POST /app/admin/mail/config-test
     * 使用当前表单参数测试SMTP连接（不发送邮件）
     */
    public function configTest(Request $request): Response
    {
        try {
            $post = (array)$request->post();

            $host = (string)($post['mail_host'] ?? blog_config('mail_host', '', false, true, false));
            $port = (int)($post['mail_port'] ?? blog_config('mail_port', 587, false, true, false));
            $enc  = (string)($post['mail_encryption'] ?? blog_config('mail_encryption', 'tls', false, true, false));

            if ($host === '' || $port <= 0) {
                return json(['code' => 1, 'msg' => '请填写有效的主机与端口']);
            }

            $scheme = ($enc === 'ssl') ? 'ssl://' : '';
            $errno = 0; $errstr = '';
            $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
            if ($fp) {
                @fclose($fp);
                return json(['code' => 0, 'msg' => 'TCP连接成功']);
            }
            return json(['code' => 1, 'msg' => $errstr ?: '连接失败']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
}