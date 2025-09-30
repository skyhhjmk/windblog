<?php

namespace app\service;


use app\model\Admin;
use app\model\Author;
use app\model\ImportJob;
use app\model\Post;
use app\model\Media;
use app\model\Setting;
use DOMNode;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use support\Log;
use Throwable;
use XMLReader;

class WordpressImporter
{
    /**
     * 导入任务实例
     *
     * @var ImportJob
     */
    protected ImportJob $importJob;

    /**
     * 导入选项
     *
     * @var array
     */
    protected mixed $options;

    /**
     * 默认作者ID
     *
     * @var int
     */
    protected mixed $defaultAuthorId;

    /**
     * 构造函数
     *
     * @param ImportJob $importJob
     */
    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
        $this->options = json_decode($importJob->options ?? '{}', true);
        $this->defaultAuthorId = $importJob->author_id ?: $this->resolveDefaultAuthorId();

        Log::info('初始化WordPress导入器，任务ID: ' . $importJob->id);
    }

    /**
     * 执行导入任务
     *
     * @return bool
     */
    public function execute()
    {
        Log::info("开始执行导入任务: " . $this->importJob->name);

        try {
            // 确保任务状态是processing
            if ($this->importJob->status !== 'processing') {
                $this->importJob->update([
                    'status' => 'processing',
                    'progress' => 0,
                    'message' => '开始导入...'
                ]);
            }

            $xmlFile = $this->importJob->file_path;

            Log::info('检查导入文件: ' . $xmlFile);

            if (!file_exists($xmlFile)) {
                throw new \Exception('导入文件不存在: ' . $xmlFile);
            }

            // 先尝试修复XML中的命名空间问题
            Log::info("修复XML命名空间");
            $fixedXmlFile = $this->fixXmlNamespaces($xmlFile);

            $reader = new XMLReader();
            Log::info("打开XML文件: " . $fixedXmlFile);
            if (!$reader->open($fixedXmlFile)) {
                throw new \Exception('无法打开XML文件: ' . $fixedXmlFile);
            }

            // 计算总项目数用于进度显示
            Log::info("计算XML项目总数");
            $totalItems = $this->countItems($fixedXmlFile);
            Log::info("XML项目总数: " . $totalItems);

            // 如果没有项目，直接完成任务
            if ($totalItems == 0) {
                $reader->close();
                if ($fixedXmlFile !== $xmlFile && file_exists($fixedXmlFile)) {
                    unlink($fixedXmlFile);
                }

                $this->importJob->update([
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => '导入完成 (无项目需要导入)',
                    'completed_at' => date('Y-m-d H:i:s')
                ]);

                Log::info("导入任务完成 (无项目): " . $this->importJob->name);
                return true;
            }

            $processedItems = 0;

            // 解析XML并导入
            Log::info("开始解析XML并导入");
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT) {
                    switch ($reader->localName) {
                        case 'item':
                            try {
                                $this->processItem($reader);
                            } catch (\Exception $e) {
                                // 重新抛出错误
                                Log::error('处理项目时出错: ' . $e->getMessage(), ['exception' => $e]);
                                throw $e;
                            }
                            $processedItems++;

                            // 更新进度
                            $progress = intval(($processedItems / max(1, $totalItems)) * 100);
                            $this->importJob->update([
                                'progress' => $progress,
                                'message' => "已处理 {$processedItems}/{$totalItems} 个项目"
                            ]);

                            Log::debug("处理项目进度: {$processedItems}/{$totalItems} ({$progress}%)");
                            break;
                    }
                }
            }

            $reader->close();

            // 删除临时修复的文件
            if ($fixedXmlFile !== $xmlFile && file_exists($fixedXmlFile)) {
                unlink($fixedXmlFile);
                Log::info("删除临时修复文件: " . $fixedXmlFile);
            }

            $this->importJob->update([
                'status' => 'completed',
                'progress' => 100,
                'message' => '导入完成',
                'completed_at' => date('Y-m-d H:i:s')
            ]);


            Log::info("导入任务完成: " . $this->importJob->name);
            return true;
        } catch (\Exception $e) {
            Log::error('导入执行错误: ' . $e->getMessage(), ['exception' => $e]);

            // 删除临时修复的文件
            $fixedXmlFile = runtime_path('imports') . '/fixed_' . basename($this->importJob->file_path);
            if (isset($fixedXmlFile) && $fixedXmlFile !== $this->importJob->file_path && file_exists($fixedXmlFile)) {
                unlink($fixedXmlFile);
                Log::info("删除临时修复文件: " . $fixedXmlFile);
            }

            $this->importJob->update([
                'status' => 'failed',
                'message' => '导入失败: ' . $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * 修复XML中的命名空间问题
     *
     * @param string $xmlFile
     *
     * @return string 修复后的文件路径
     */
    protected function fixXmlNamespaces($xmlFile)
    {
        try {
            Log::info("读取XML文件内容");
            $content = file_get_contents($xmlFile);

            // 添加常见的WordPress导出文件中可能缺失的命名空间定义
            $namespaceFixes = [
                'xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"',
                'xmlns:content="http://purl.org/rss/1.0/modules/content/"',
                'xmlns:wfw="http://wellformedweb.org/CommentAPI/"',
                'xmlns:dc="http://search.yahoo.com/mrss/"',
                'xmlns:wp="http://wordpress.org/export/1.2/"',
                'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"' // 添加itunes命名空间
            ];

            // 检查是否已经包含这些命名空间
            $rssTagPos = strpos($content, '<rss');
            if ($rssTagPos !== false) {
                $rssEndPos = strpos($content, '>', $rssTagPos);
                if ($rssEndPos !== false) {
                    $rssTag = substr($content, $rssTagPos, $rssEndPos - $rssTagPos + 1);
                    Log::info("原始RSS标签: " . $rssTag);
                    $missingNamespaces = [];

                    foreach ($namespaceFixes as $namespace) {
                        // 检查是否已经存在该命名空间
                        if (strpos($rssTag, explode('=', $namespace)[0]) === false) {
                            $missingNamespaces[] = $namespace;
                        }
                    }

                    // 如果有缺失的命名空间，添加它们
                    if (!empty($missingNamespaces)) {
                        $newRssTag = substr($rssTag, 0, -1) . ' ' . implode(' ', $missingNamespaces) . '>';
                        Log::info("修复后的RSS标签: " . $newRssTag);
                        $content = substr_replace($content, $newRssTag, $rssTagPos, strlen($rssTag));

                        // 保存修复后的文件
                        $fixedXmlFile = runtime_path('imports') . '/fixed_' . basename($xmlFile);
                        file_put_contents($fixedXmlFile, $content);
                        Log::info("创建修复后的XML文件: " . $fixedXmlFile);
                        return $fixedXmlFile;
                    } else {
                        Log::info("XML文件命名空间完整，无需修复");
                    }
                }
            }

            // 如果没有需要修复的，返回原文件路径
            return $xmlFile;
        } catch (\Exception $e) {
            Log::warning('修复XML命名空间时出错: ' . $e->getMessage());
            // 出错时返回原始文件路径
            return $xmlFile;
        }
    }

    /**
     * 计算XML中项目总数
     *
     * @param string $xmlFile
     *
     * @return int
     */
    protected function countItems($xmlFile)
    {
        Log::info("计算XML项目数: " . $xmlFile);
        $reader = new XMLReader();
        if (!$reader->open($xmlFile)) {
            Log::warning("无法打开XML文件进行计数: " . $xmlFile);
            return 0;
        }

        $count = 0;
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $count++;
            }
        }

        $reader->close();
        Log::info("XML项目数计算完成: " . $count);
        return $count;
    }

    /**
     * 处理单个项目
     *
     * @param XMLReader $reader
     *
     * @return void
     */
    protected function processItem(XMLReader $reader): void
    {
        Log::debug("处理XML项目");
        $doc = new \DOMDocument();
        // 禁用错误报告以避免命名空间警告
        $libxmlErrors = libxml_use_internal_errors(true);
        $node = $doc->importNode($reader->expand(), true);
        $doc->appendChild($node);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');
        $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
        $xpath->registerNamespace('dc', 'http://search.yahoo.com/mrss/');
        $xpath->registerNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');

        // 恢复错误报告设置
        libxml_use_internal_errors($libxmlErrors);

        $postType = $xpath->evaluate('string(wp:post_type)', $node);
        Log::debug("项目类型: " . $postType);

        // 根据不同类型处理
        switch ($postType) {
            case 'post':
            case 'page':
                $this->processPost($xpath, $node, $postType);
                break;
            case 'attachment':
                if (!empty($this->options['import_attachments'])) {
                    $this->processAttachment($xpath, $node);
                }
                break;
            default:
                Log::debug("忽略不支持的项目类型: " . $postType);
        }
    }

    /**
     * 处理文章或页面
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     * @param string   $postType
     *
     * @return void
     * @throws Throwable
     */
    protected function processPost(DOMXPath $xpath, DOMNode $node, string $postType): void
    {
        Log::debug("处理文章/页面");
        $title = $xpath->evaluate('string(title)', $node);
        $content = $xpath->evaluate('string(content:encoded)', $node);
        $excerpt = $xpath->evaluate('string(excerpt:encoded)', $node);
        $status = $xpath->evaluate('string(wp:status)', $node);
        $slug = $xpath->evaluate('string(wp:post_name)', $node);
        $postDate = $xpath->evaluate('string(wp:post_date)', $node);
        $wpPostId = $xpath->evaluate('string(wp:post_id)', $node);

        // 首先将内容转换为UTF-8编码
        $title = $this->convertToUtf8($title);
        $content = $this->convertToUtf8($content);
        $excerpt = $this->convertToUtf8($excerpt);

        Log::debug("文章信息 - 标题: " . $title . ", 状态: " . $status . ", 类型: " . $postType);

        switch ($this->options['convert_to']) {
            case 'markdown':
                $content_type = 'markdown';
                // 将HTML内容转换为Markdown
                if (!empty($content)) {
                    $content = $this->convertHtmlToMarkdown($content);
                }

                if (!empty($excerpt)) {
                    $excerpt = $this->convertHtmlToMarkdown($excerpt);
                }
                break;
            case 'html':
                $content_type = 'html';
                break;
        }

        // 处理作者
        $authorId = $this->processAuthor($xpath, $node);

        // 处理分类
        $categoryId = $this->processCategories($xpath, $node);

        // 转换状态
        $statusMap = [
            'publish' => 'published',
            'draft' => 'draft',
            'private' => 'draft',
            'pending' => 'draft',
            'future' => 'draft'
        ];
        $status = $statusMap[$status] ?? 'draft';

        // 处理slug
        if (empty($slug)) {
            if (!empty($title)) {
                // 检查是否为"未命名"之类的标题
                $trimmedTitle = trim($title);
                $unnamedPatterns = ['未命名', 'unnamed', 'untitled', '无标题', 'default', 'new post'];

                $isUnnamed = false;
                foreach ($unnamedPatterns as $pattern) {
                    if (stripos($trimmedTitle, $pattern) !== false) {
                        $isUnnamed = true;
                        break;
                    }
                }

                if ($isUnnamed || mb_strlen($trimmedTitle) == 0) {
                    // 使用ID作为slug
                    $slug = !empty($wpPostId) ? $wpPostId : uniqid();
                } else {
                    // 尝试翻译标题
                    $translatedTitle = $this->translateTitle($title);
                    $slug = \Illuminate\Support\Str::slug($translatedTitle);

                    // 如果翻译后的slug仍然为空，则使用原文
                    if (empty($slug)) {
                        $slug = \Illuminate\Support\Str::slug($title);
                    }

                    // 如果仍然为空，则使用ID
                    if (empty($slug)) {
                        $slug = !empty($wpPostId) ? $wpPostId : uniqid();
                    }
                }
            } else {
                // 标题为空，使用ID作为slug
                $slug = !empty($wpPostId) ? $wpPostId : uniqid();
            }
        }

        // 确保slug唯一性
        $originalSlug = $slug;
        $suffix = 1;
        while (Post::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $suffix;
            $suffix++;
        }

        // 检查是否已存在相同标题的文章
        $existingPost = null;
        if ($title) {
            $existingPost = Post::where('title', $title)->first();
        }

        // 根据重复处理模式决定如何处理
        $duplicateMode = $this->options['duplicate_mode'] ?? 'skip';

        // 如果存在相同标题的文章
        if ($existingPost) {
            switch ($duplicateMode) {
                case 'overwrite':
                    // 覆盖模式：更新现有文章
                    Log::debug("覆盖模式：更新现有文章，ID: " . $existingPost->id);
                    $existingPost->content_type = $content_type;
                    $existingPost->content = $content;
                    $existingPost->excerpt = $excerpt;
                    $existingPost->status = $status;
                    $existingPost->slug = $slug;
                    $existingPost->updated_at = date('Y-m-d H:i:s');
                    $existingPost->save();

                    // 更新作者关联
                    if ($authorId) {
                        // 获取作者模型实例
                        $author = \app\model\Author::find($authorId);
                        if ($author) {
                            // 使用attach方法更新文章作者关联
                            // 先清除现有关联
                            $existingPost->authors()->detach();
                            // 再添加新的关联，并设置额外字段
                            $existingPost->authors()->attach($author, [
                                'is_primary' => 1,
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }

                    // 更新分类关联
                    if ($categoryId) {
                        // 获取分类模型实例
                        $category = \app\model\Category::find($categoryId);
                        if ($category) {
                            // 使用attach方法更新文章分类关联
                            // 先清除现有关联
                            $existingPost->categories()->detach();
                            // 再添加新的关联
                            $existingPost->categories()->attach($category);
                        }
                    }

                    Log::debug("文章更新完成，ID: " . $existingPost->id);
                    return;

                case 'skip':
                default:
                    // 跳过模式：记录日志并跳过
                    Log::debug("跳过模式：跳过重复文章，标题: " . $title);
                    return;
            }
        }

        // 保存新文章
        $post = new Post();
        $post->title = $title ?: '无标题';
        $post->content_type = $content_type;
        $post->content = $content;
        // 生成文章摘要时首先参考示例代码转换成HTML，再删除HTML标签
        $post->excerpt = $this->generateExcerpt($excerpt ?: $content);
        $post->status = $status;
        $post->slug = $slug;
        $post->created_at = $postDate && $postDate !== '0000-00-00 00:00:00' ? date('Y-m-d H:i:s', strtotime($postDate)) : date('Y-m-d H:i:s');
        $post->updated_at = date('Y-m-d H:i:s');

        Log::debug("保存文章: " . $post->title);
        $post->save();

        // 保存文章作者关联
        if ($authorId) {
            // 获取作者模型实例
            $author = \app\model\Author::find($authorId);
            if ($author) {
                // 使用attach方法保存文章作者关联
                $post->authors()->attach($author, [
                    'is_primary' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                Log::debug("文章作者关联已保存，文章ID: " . $post->id . "，作者ID: " . $authorId);
            }
        }

        // 保存文章分类关联
        if ($categoryId) {
            // 获取分类模型实例
            $category = \app\model\Category::find($categoryId);
            if ($category) {
                // 使用attach方法保存文章分类关联
                $post->categories()->attach($category);
                Log::debug("文章分类关联已保存，文章ID: " . $post->id . "，分类ID: " . $categoryId);
            }
        }

        Log::debug("文章保存完成，ID: " . $post->id);
    }

    /**
     * 翻译标题（使用免费翻译API）
     *
     * @param string $title
     *
     * @return string
     * @throws Throwable
     */
    protected function translateTitle(string $title): string
    {
        try {
            // 使用百度翻译API（免费版）
            $appid = blog_config('baidu_translate_appid', '', true); // 你的百度翻译API AppID
            $secret = blog_config('baidu_translate_secret', '', true); // 你的百度翻译API密钥

            // 如果没有配置API密钥，则直接返回原标题
            if (empty($appid) || empty($secret)) {
                return $this->formatTitleAsSlug($title);
            }

            // 检查标题是否已经是英文
            if (preg_match('/^[A-Za-z0-9\s\-_]+$/', $title)) {
                return $this->formatTitleAsSlug($title);
            }

            $url = 'https://fanyi-api.baidu.com/api/trans/vip/translate';
            $salt = time();
            $sign = md5($appid . $title . $salt . $secret);

            $params = [
                'q' => $title,
                'from' => 'auto',
                'to' => 'en',
                'appid' => $appid,
                'salt' => $salt,
                'sign' => $sign
            ];

            $response = @file_get_contents($url . '?' . http_build_query($params));
            if ($response) {
                $result = json_decode($response, true);
                if (isset($result['trans_result'][0]['dst'])) {
                    return $this->formatTitleAsSlug($result['trans_result'][0]['dst']);
                }
            }
        } catch (\Exception $e) {
            Log::warning('翻译标题时出错: ' . $e->getMessage());
        }

        // 翻译失败时返回格式化后的原标题
        return $this->formatTitleAsSlug($title);
    }

    /**
     * 将标题格式化为slug形式（单词间用连字符连接）
     *
     * @param string $title
     *
     * @return string
     */
    protected function formatTitleAsSlug(string $title): string
    {
        // 移除多余空格并转换为小写
        $title = trim(strtolower($title));

        // 将空格和特殊字符替换为连字符
        $title = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}]+/u', '-', $title);

        // 将驼峰命名转换为连字符分隔
        $title = preg_replace('/([a-z])([A-Z])/', '$1-$2', $title);

        // 移除开头和结尾的连字符
        $title = trim($title, '-');

        // 如果结果为空，返回唯一标识
        if (empty($title)) {
            return uniqid();
        }

        return $title;
    }


    /**
     * 处理作者
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     *
     * @return int|null
     */
    protected function processAuthor(DOMXPath $xpath, DOMNode $node): ?int
    {
        $authorName = $xpath->evaluate('string(dc:creator)', $node);
        Log::debug("处理作者: " . $authorName);

        // 优先使用任务指定默认作者
        if (empty($authorName)) {
            Log::debug('使用默认作者ID: ' . var_export($this->defaultAuthorId, true));
            return $this->defaultAuthorId;
        }

        // 按导出的作者名查找现有普通用户
        $author = Author::where('username', $authorName)->first();
        if ($author) {
            Log::debug('找到现有用户: ' . $author->username . ' (ID: ' . $author->id . ')');
            return (int)$author->id;
        }

        // 找不到则回退到默认作者
        Log::debug('未找到作者，回退默认作者ID: ' . var_export($this->defaultAuthorId, true));
        return $this->defaultAuthorId;
    }


    /**
     * 处理分类
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     *
     * @return int|null
     */
    protected function processCategories(DOMXPath $xpath, DOMNode $node): ?int
    {
        $categories = $xpath->query('category[@domain="category"]', $node);

        if ($categories->length > 0) {
            $categoryName = $categories->item(0)->nodeValue;
            Log::debug("处理分类: " . $categoryName);

            // 查找现有分类
            $category = \app\model\Category::where('name', $categoryName)->first();

            if ($category) {
                Log::debug("找到现有分类: " . $category->name . " (ID: " . $category->id . ")");
                return $category->id;
            }

            // 创建新分类
            Log::debug("创建新分类: " . $categoryName);

            // 生成slug，确保不为空
            $slug = \Illuminate\Support\Str::slug($categoryName);
            if (empty($slug)) {
                // 如果生成的slug为空，则使用分类名加上时间戳
                $slug = 'category-' . time();
            }

            // 检查slug是否已存在，如果存在则添加唯一后缀
            $originalSlug = $slug;
            $suffix = 1;
            while (\app\model\Category::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $suffix;
                $suffix++;
            }

            // 使用Category模型创建新分类
            try {
                $category = new \app\model\Category();
                $category->name = $categoryName;
                $category->slug = $slug;
                $category->parent_id = 0; // 默认作为顶级分类
                $category->sort_order = 0; // 默认排序
                $category->created_at = date('Y-m-d H:i:s');
                $category->updated_at = date('Y-m-d H:i:s');
                $category->save();

                Log::debug("新分类创建完成，ID: " . $category->id);
                return $category->id;
            } catch (\Exception $e) {
                Log::error('创建分类时出错: ' . $e->getMessage());
                return null;
            }
        }

        Log::debug("无分类信息");
        return null;
    }

    /**
     * 解析默认作者ID：
     * 1) 任务中已指定 author_id 则使用
     * 2) 否则查找安装时创建的普通用户（nickname=超级管理员）
     * 3) 否则使用任意一个已有用户（id最小）
     * 4) 都没有则返回null
     */
    protected function resolveDefaultAuthorId(): ?int
    {
        try {
            // 1) 若任务已指定 author_id，构造函数中已优先使用，这里不重复判断

            // 2) 查找管理员用户名（取第一个管理员作为安装默认管理员）
            $admin = Admin::orderBy('id', 'asc')->first();
            if ($admin && !empty($admin->username)) {
                // 使用与管理员相同用户名的普通用户作为默认作者
                $user = Author::where('username', $admin->username)->first();
                if ($user) {
                    return (int)$user->id;
                }
            }

            // 3) 回退：选取任意一个已有用户
            $any = Author::orderBy('id', 'asc')->first();
            if ($any) {
                return (int)$any->id;
            }
        } catch (\Throwable $e) {
            Log::warning('解析默认作者ID失败: ' . $e->getMessage());
        }
        return null;
    }


    /**
     * 处理附件
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     *
     * @return void
     */
    protected function processAttachment(DOMXPath $xpath, DOMNode $node): void
    {
        $title = $xpath->evaluate('string(title)', $node);
        $url = $xpath->evaluate('string(wp:attachment_url)', $node);

        Log::debug("处理附件 - 标题: " . $title . ", URL: " . $url);

        if (empty($url)) {
            Log::debug("附件URL为空，跳过");
            return;
        }

        // 如果需要下载附件
        if (!empty($this->options['download_attachments'])) {
            $this->downloadAttachment($url, $title);
        }
    }

    /**
     * 下载附件
     *
     * @param string $url
     * @param string $title
     *
     * @return void
     */
    protected function downloadAttachment(string $url, string $title): void
    {
        /*try {
            \support\Log::debug("下载附件: " . $url);
            
            // 创建上传目录
            $uploadDir = public_path('uploads');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // 生成文件路径
            $filename = basename($url);
            // 清理文件名
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename);
            $relativePath = date('Y/m') . '/' . $filename;
            $filePath = $uploadDir . '/' . $relativePath;
            $fileDir = dirname($filePath);
            
            if (!is_dir($fileDir)) {
                mkdir($fileDir, 0755, true);
            }
            
            // 下载文件
            $fileContent = @file_get_contents($url);
            if ($fileContent === false) {
                \support\Log::warning("无法下载附件: " . $url);
                return; // 下载失败
            }
            
            file_put_contents($filePath, $fileContent);
            
            // 保存到媒体表
            $media = new Media();
            $media->filename = $filename;
            $media->original_name = $title ?: $filename;
            $media->file_path = $relativePath;
            $media->file_size = strlen($fileContent);
            $media->mime_type = mime_content_type($filePath) ?: 'application/octet-stream';
            $media->author_id = $this->defaultAuthorId; // 可能为null
            $media->created_at = date('Y-m-d H:i:s');
            $media->updated_at = date('Y-m-d H:i:s');
            
            \support\Log::debug("保存媒体文件记录");
            $media->save();
            \support\Log::debug("媒体文件保存完成，ID: " . $media->id);
        } catch (\Exception $e) {
            \support\Log::error('下载附件时出错: ' . $e->getMessage(), ['exception' => $e]);
        }*/
    }

    /**
     * 将HTML转换为Markdown
     *
     * @param string $html
     *
     * @return string
     */
    protected function convertHtmlToMarkdown(string $html, array $config = []): string
    {
        if (empty($config)) {
            $config = [
                'strip_tags' => true,
            ];
        }

        $converter = new HtmlConverter($config);

        $markdown = $converter->convert($html);

        return trim($markdown);
    }

    /**
     * 清理runtime/imports目录
     *
     * @return void
     */
    protected function cleanImportDirectory(): void
    {
        try {
            $importDir = runtime_path('imports');
            if (!is_dir($importDir)) {
                return;
            }

            // 获取目录中的所有文件
            $files = scandir($importDir);
            foreach ($files as $file) {
                // 跳过当前目录和上级目录
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $filePath = $importDir . DIRECTORY_SEPARATOR . $file;

                // 删除文件（不删除目录）
                if (is_file($filePath)) {
                    unlink($filePath);
                    Log::info("删除导入临时文件: " . $filePath);
                }
            }

            Log::info("导入目录清理完成: " . $importDir);
        } catch (\Exception $e) {
            Log::error('清理导入目录时出错: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * 将字符串转换为UTF-8编码
     *
     * @param string $string
     *
     * @return string
     */
    protected function convertToUtf8(string $string): string
    {
        if (empty($string)) {
            return $string;
        }

        // 检测当前编码
        $encoding = mb_detect_encoding($string, ['UTF-8', 'GBK', 'GB2312', 'ASCII', 'ISO-8859-1'], true);

        // 如果不是UTF-8编码，则转换为UTF-8
        if ($encoding !== 'UTF-8') {
            $string = mb_convert_encoding($string, 'UTF-8', $encoding);
        }

        return $string;
    }

    /**
     * 生成文章摘要：首先将内容转换为HTML，然后删除HTML标签
     *
     * @param string $content
     *
     * @return string
     */
    protected function generateExcerpt(string $content): string
    {
        if (empty($content)) {
            return '';
        }

        // 使用CommonMarkConverter将内容转换为HTML，配置更多选项以提高转换质量
        $config = [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
            'renderer' => [
                'soft_break' => "<br />\n",
            ],
            'commonmark' => [
                'enable_em' => true,
                'enable_strong' => true,
                'use_asterisk' => true,
                'use_underscore' => true,
                'unordered_list_markers' => ['-', '+', '*'],
            ],
            'heading' => [
                'render_modifiers' => [],
            ]
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new TaskListExtension());

        $converter = new MarkdownConverter($environment);

        $html = $converter->convert($content);

        // 删除HTML标签
        $excerpt = strip_tags((string)$html);

        // 截取前200个字符作为摘要
        return mb_substr($excerpt, 0, 200, 'UTF-8');
    }


}