<?php

namespace app\controller;

use app\service\CacheControl;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 骨架页控制器
 *
 * 提供独立的骨架页,支持 CDN 和浏览器缓存
 * 显示详细的加载进度信息
 */
class SkeletonController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    /**
     * 返回骨架页
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $target = $request->get('target', '/');
        $timestamp = $request->get('t', time());

        $html = $this->generateSkeletonHtml($target, $timestamp);

        $headers = CacheControl::getSkeletonPageHeaders();

        return new Response(200, array_merge($headers, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Skeleton' => '1',
        ]), $html);
    }

    /**
     * 生成骨架页 HTML
     *
     * @param string $target    目标页面 URL
     * @param int    $timestamp 时间戳
     *
     * @return string
     */
    protected function generateSkeletonHtml(string $target, int $timestamp): string
    {
        $encodedTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#667eea">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0f19">
<title>加载中…</title>
<script>(function(){try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);document.documentElement.classList.toggle('dark',t==='dark')}}catch(e){}})()</script>
<style>
html,body{height:100%;margin:0;background:#f9fafb;font-family:system-ui,-apple-system,sans-serif}
.c{display:flex;align-items:center;justify-content:center;height:100%;flex-direction:column;padding:20px;box-sizing:border-box}
.s{width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:s .8s linear infinite}
.t{margin-top:16px;color:#6b7280;font-size:14px}
#progress-container{margin-top:20px;width:100%;max-width:500px}
.resource-item{margin-top:8px;padding:8px;background:#fff;border-radius:4px;border:1px solid #e5e7eb}
.resource-name{color:#374151;font-size:12px;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.resource-progress{height:4px;background:#f3f4f6;border-radius:2px;overflow:hidden}
.resource-bar{height:100%;background:#3b82f6;transition:width .2s}
.resource-info{display:flex;justify-content:space-between;margin-top:4px;font-size:11px;color:#6b7280}
#overall-progress{position:fixed;top:0;left:0;height:3px;background:#3b82f6;width:0;transition:width .3s;z-index:9999}
#overall-info{margin-top:12px;color:#6b7280;font-size:13px;text-align:center}
@keyframes s{to{transform:rotate(360deg)}}
html[data-theme=dark],html[data-theme=dark] body{background:#0b0f19;color:#e5e7eb}
html[data-theme=dark] .t{color:#cbd5e1}
html[data-theme=dark] .s{border-color:#374151;border-top-color:#8b5cf6}
html[data-theme=dark] #overall-progress{background:#8b5cf6}
html[data-theme=dark] .resource-item{background:#1f2937;border-color:#374151}
html[data-theme=dark] .resource-name{color:#e5e7eb}
html[data-theme=dark] .resource-progress{background:#374151}
html[data-theme=dark] .resource-bar{background:#8b5cf6}
html[data-theme=dark] .resource-info{color:#9ca3af}
html[data-theme=dark] #overall-info{color:#9ca3af}
@media (prefers-color-scheme: dark){
  body{background:#0b0f19;color:#e5e7eb}
  .t{color:#cbd5e1}
  .s{border-color:#374151;border-top-color:#8b5cf6}
  #overall-progress{background:#8b5cf6}
  .resource-item{background:#1f2937;border-color:#374151}
  .resource-name{color:#e5e7eb}
  .resource-progress{background:#374151}
  .resource-bar{background:#8b5cf6}
  .resource-info{color:#9ca3af}
  #overall-info{color:#9ca3af}
}
</style>
</head>
<body>
<div id="overall-progress"></div>
<div class="c">
<div class="s"></div>
<div class="t">首屏构建中…</div>
<div id="overall-info">准备加载…</div>
<div id="progress-container"></div>
</div>
<script>
(function(){
var target='{$encodedTarget}';
var startTime=Date.now();
var totalBytes=0;
var loadedBytes=0;
var resources=new Map();
var maxResources=8;

function formatSpeed(bytes,ms){
if(ms<1)return'0 KB/s';
var bps=bytes/ms*1000;
if(bps<1024)return Math.round(bps)+' B/s';
if(bps<1048576)return (bps/1024).toFixed(1)+' KB/s';
return (bps/1048576).toFixed(2)+' MB/s';
}

function formatSize(bytes){
if(bytes<1024)return bytes+' B';
if(bytes<1048576)return (bytes/1024).toFixed(1)+' KB';
return (bytes/1048576).toFixed(2)+' MB';
}

function formatTime(ms){
if(ms<1000)return ms.toFixed(2)+'ms';
if(ms<60000)return (ms/1000).toFixed(2)+'s';
var minutes=Math.floor(ms/60000);
var seconds=((ms%60000)/1000).toFixed(2);
return minutes+'m '+seconds+'s';
}

// 防抖函数，限制函数调用频率
function debounce(func, wait){
var timeout;
return function executedFunction(...args){
var later=function(){
clearTimeout(timeout);
func(...args);
};
clearTimeout(timeout);
setTimeout(later,wait);
};
}

// 保存上一次计算的剩余时间
var lastRemainingTime='计算中…';
var lastProgress=0;

function updateOverallProgress(){
var percent=totalBytes>0?(loadedBytes/totalBytes*100):0;
try{document.getElementById('overall-progress').style.width=percent+'%'}catch(e){}
var elapsed=Date.now()-startTime;
var speed=loadedBytes>0?formatSpeed(loadedBytes,elapsed):'0 KB/s';

// 计算剩余时间，使用防抖逻辑
var remaining='计算中…';
if(loadedBytes>0&&speed!=='0 KB/s'&&percent<99){
var remainingMs=(totalBytes-loadedBytes)/(loadedBytes/elapsed*1000);
// 剩余时间不超过10分钟
remainingMs=Math.min(remainingMs,600000);
// 只在进度变化超过1%或首次计算时更新剩余时间
if(Math.abs(percent-lastProgress)>=1||lastRemainingTime==='计算中…'){
remaining=formatTime(remainingMs);
lastRemainingTime=remaining;
lastProgress=percent;
}else{
// 使用上一次计算的剩余时间，减少闪烁
remaining=lastRemainingTime;
}
}

// 进度接近100%时，显示"即将完成"
if(percent>=99&&percent<100){
remaining='即将完成';
}

// 进度100%时，显示"完成"
if(percent>=100){
remaining='完成';
}

try{document.getElementById('overall-info').textContent='已加载 '+formatSize(loadedBytes)+' / '+formatSize(totalBytes)+' ('+Math.round(percent)+'%) | '+speed+' | 剩余 '+remaining}catch(e){}
}

function addResource(id,name,size){
if(resources.size>=maxResources){
var oldest=resources.keys().next().value;
removeResource(oldest);
}
var div=document.createElement('div');
div.className='resource-item';
div.id='resource-'+id;
div.innerHTML='<div class="resource-name">'+name+'</div><div class="resource-progress"><div class="resource-bar" id="bar-'+id+'" style="width:0%"></div></div><div class="resource-info"><span id="loaded-'+id+'">0 KB</span><span id="speed-'+id+'">0 KB/s</span></div>';
try{document.getElementById('progress-container').appendChild(div)}catch(e){}
resources.set(id,{name:name,size:size,loaded:0,startTime:Date.now()});
totalBytes+=size;
updateOverallProgress();
}

function updateResource(id,loaded){
var res=resources.get(id);
if(!res)return;
var delta=loaded-res.loaded;
loadedBytes+=delta;
res.loaded=loaded;
try{document.getElementById('bar-'+id).style.width=(res.size>0?loaded/res.size*100:0)+'%'}catch(e){}
try{document.getElementById('loaded-'+id).textContent=formatSize(loaded)+' / '+formatSize(res.size)}catch(e){}
var elapsed=Date.now()-res.startTime;
var speed=loaded>0?formatSpeed(loaded,elapsed):'0 KB/s';
try{document.getElementById('speed-'+id).textContent=speed}catch(e){}
updateOverallProgress();
}

function removeResource(id){
var res=resources.get(id);
if(!res)return;
totalBytes-=res.size;
loadedBytes-=res.loaded;
var el=document.getElementById('resource-'+id);
if(el)try{el.remove()}catch(e){}
resources.delete(id);
updateOverallProgress();
}

function completeResource(id){
var res=resources.get(id);
if(!res)return;
var el=document.getElementById('resource-'+id);
if(el){
try{el.querySelector('.resource-bar').style.background='#10b981'}catch(e){}
setTimeout(function(){removeResource(id)},500);
}
}

function load(){
try{
var c=new AbortController();
setTimeout(function(){try{c.abort()}catch(e){}},20e3);

var sep=target.indexOf('?')===-1?'?':'&';
var bypassUrl=target+sep+'_instant_bypass=1&t='+Date.now();

var perf=performance.getEntriesByType('navigation')[0];
if(perf&&perf.domComplete){
var domTime=perf.domComplete-perf.fetchStart;
updateOverallProgress();
}

// 添加初始资源显示
addResource('html','页面 HTML',1000000);
updateResource('html',0);

fetch(bypassUrl,{headers:{'X-INSTANT-BYPASS':'1'},signal:c.signal,credentials:'same-origin'})
.then(function(r){
var contentLength=parseInt(r.headers.get('Content-Length')||'0');
if(contentLength>0){
// 更新资源大小
var res=resources.get('html');
if(res){
resources.set('html',{...res,size:contentLength});
totalBytes=totalBytes-res.size+contentLength;
}
}
var reader=r.body.getReader();
var chunks=[];
var received=0;

return new Promise(function(resolve,reject){
function read(){
reader.read().then(function(result){
if(result.done){
if(contentLength>0){
completeResource('html');
}
resolve(new Response(new Blob(chunks),{headers:r.headers}));
return;
}
var chunk=result.value;
chunks.push(chunk);
received+=chunk.length;
if(contentLength>0){
updateResource('html',received);
}else{
// 没有 Content-Length 时的模拟进度
var simulatedSize=Math.max(1000000,received*2);
var res=resources.get('html');
if(res){
resources.set('html',{...res,size:simulatedSize});
totalBytes=received;
updateResource('html',received);
}
}
read();
}).catch(function(e){
console.error('读取失败:',e);
reject(e);
});
}
read();
});
})
.then(function(r){
return r.text()
})
.then(function(h){
// 确保骨架页至少显示 1 秒钟
var elapsed=Date.now()-startTime;
var remainingTime=Math.max(1000-elapsed,200);
setTimeout(function(){
try{document.open();document.write(h);document.close()}catch(e){
location.href=target
}},remainingTime)
})
.catch(function(e){
console.error('加载失败:',e);
try{document.getElementById('overall-info').textContent='加载失败,正在重试…'}catch(e){}
setTimeout(function(){location.href=target},2000)
})
}catch(e){
console.error('异常:',e);
location.href=target
}
}

if(document.readyState==='loading'){
document.addEventListener('DOMContentLoaded',load)
}else{
load()
}
})()
</script>
</body>
</html>
HTML;
    }
}
