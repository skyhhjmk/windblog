<?php

namespace app\service;

use app\model\Admin;
use app\model\Author;
use app\model\ImportJob;
use app\model\Post;
use DOMNode;
use DOMXPath;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\HTMLToMarkdown\HtmlConverter;
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
     * 媒体库服务实例
     *
     * @var MediaLibraryService
     */
    protected MediaLibraryService $mediaLibraryService;

    /**
     * 附件映射关系 [原始URL => 媒体信息]
     *
     * @var array
     */
    protected array $attachmentMap = [];

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
        $this->mediaLibraryService = new MediaLibraryService();

        Log::info('初始化WordPress导入器，任务ID: ' . $importJob->id);
    }

    /**
     * 执行导入任务
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::info('开始执行WordPress导入任务: ' . $this->importJob->name);

            // 修复XML文件命名空间问题
            $fixedXmlFile = $this->fixXmlNamespaces($this->importJob->file_path);
            if (!$fixedXmlFile) {
                throw new \Exception('XML文件修复失败');
            }

            // 计算项目总数
            $totalItems = $this->countItems($fixedXmlFile);
            if ($totalItems === 0) {
                throw new \Exception('XML文件中没有找到任何项目');
            }

            Log::info('开始处理XML项目，总数: ' . $totalItems);

            // 第一阶段：处理所有附件，建立完整的附件映射关系
            $this->processAllAttachments($fixedXmlFile, $totalItems);

            // 第二阶段：处理所有文章和页面，使用完整的附件映射关系
            $this->processAllPosts($fixedXmlFile, $totalItems);

            // 清理临时文件
            if ($fixedXmlFile !== $this->importJob->file_path && file_exists($fixedXmlFile)) {
                unlink($fixedXmlFile);
                Log::info('删除临时修复文件: ' . $fixedXmlFile);
            }

            // 清空附件映射关系
            $this->attachmentMap = [];
            Log::debug('清空附件映射关系');

            Log::info('WordPress XML导入任务处理完成: ' . $this->importJob->name);

            return true;

        } catch (\Exception $e) {
            Log::error('导入任务执行错误: ' . $e->getMessage(), ['exception' => $e]);

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
            Log::info('读取XML文件内容');
            $content = file_get_contents($xmlFile);

            // 添加常见的WordPress导出文件中可能缺失的命名空间定义
            $namespaceFixes = [
                'xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"',
                'xmlns:content="http://purl.org/rss/1.0/modules/content/"',
                'xmlns:wfw="http://wellformedweb.org/CommentAPI/"',
                'xmlns:dc="http://search.yahoo.com/mrss/"',
                'xmlns:wp="http://wordpress.org/export/1.2/"',
                'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"', // 添加itunes命名空间
            ];

            // 检查是否已经包含这些命名空间
            $rssTagPos = strpos($content, '<rss');
            if ($rssTagPos !== false) {
                $rssEndPos = strpos($content, '>', $rssTagPos);
                if ($rssEndPos !== false) {
                    $rssTag = substr($content, $rssTagPos, $rssEndPos - $rssTagPos + 1);
                    Log::info('原始RSS标签: ' . $rssTag);
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
                        Log::info('修复后的RSS标签: ' . $newRssTag);
                        $content = substr_replace($content, $newRssTag, $rssTagPos, strlen($rssTag));

                        // 保存修复后的文件
                        $fixedXmlFile = runtime_path('imports') . '/fixed_' . basename($xmlFile);
                        file_put_contents($fixedXmlFile, $content);
                        Log::info('创建修复后的XML文件: ' . $fixedXmlFile);

                        return $fixedXmlFile;
                    } else {
                        Log::info('XML文件命名空间完整，无需修复');
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
        Log::info('计算XML项目数: ' . $xmlFile);
        $reader = new XMLReader();
        if (!$reader->open($xmlFile)) {
            Log::warning('无法打开XML文件进行计数: ' . $xmlFile);

            return 0;
        }

        $count = 0;
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $count++;
            }
        }

        $reader->close();
        Log::info('XML项目数计算完成: ' . $count);

        return $count;
    }

    /**
     * 第一阶段：处理所有附件，建立完整的附件映射关系
     *
     * @param string $xmlFile    XML文件路径
     * @param int    $totalItems 总项目数
     *
     * @return void
     */
    protected function processAllAttachments(string $xmlFile, int $totalItems): void
    {
        Log::info('开始处理所有附件');

        $reader = new XMLReader();
        if (!$reader->open($xmlFile)) {
            throw new \Exception('无法打开XML文件: ' . $xmlFile);
        }

        $processedItems = 0;
        $attachmentCount = 0;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $processedItems++;

                try {
                    $doc = new \DOMDocument();
                    $libxmlErrors = libxml_use_internal_errors(true);
                    $node = $doc->importNode($reader->expand(), true);
                    $doc->appendChild($node);

                    $xpath = new DOMXPath($doc);
                    $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');

                    // 恢复错误报告设置
                    libxml_use_internal_errors($libxmlErrors);

                    $postType = $xpath->evaluate('string(wp:post_type)', $node);

                    // 只处理附件类型
                    if ($postType === 'attachment' && !empty($this->options['import_attachments'])) {
                        $attachmentInfo = $this->processAttachment($xpath, $node);
                        if ($attachmentInfo && $attachmentInfo['media_id']) {
                            // 保存附件映射关系
                            $this->attachmentMap[$attachmentInfo['url']] = $attachmentInfo;
                            $attachmentCount++;
                            Log::debug('保存附件映射: ' . $attachmentInfo['url'] . ' => 媒体ID: ' . $attachmentInfo['media_id']);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('处理附件项目时出错: ' . $e->getMessage(), ['exception' => $e]);
                }

                // 更新进度（第一阶段占50%进度）
                $progress = intval(($processedItems / max(1, $totalItems)) * 50);
                $this->importJob->update([
                    'progress' => $progress,
                    'message' => "第一阶段：处理附件 ({$processedItems}/{$totalItems})",
                ]);
            }
        }

        $reader->close();
        Log::info('附件处理完成，共处理附件: ' . $attachmentCount . ' 个');
    }

    /**
     * 第二阶段：处理所有文章和页面，使用完整的附件映射关系
     *
     * @param string $xmlFile    XML文件路径
     * @param int    $totalItems 总项目数
     *
     * @return void
     */
    protected function processAllPosts(string $xmlFile, int $totalItems): void
    {
        Log::info('开始处理所有文章和页面');

        $reader = new XMLReader();
        if (!$reader->open($xmlFile)) {
            throw new \Exception('无法打开XML文件: ' . $xmlFile);
        }

        $processedItems = 0;
        $postCount = 0;
        $pageCount = 0;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $processedItems++;

                try {
                    $doc = new \DOMDocument();
                    $libxmlErrors = libxml_use_internal_errors(true);
                    $node = $doc->importNode($reader->expand(), true);
                    $doc->appendChild($node);

                    $xpath = new DOMXPath($doc);
                    $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');

                    // 恢复错误报告设置
                    libxml_use_internal_errors($libxmlErrors);

                    $postType = $xpath->evaluate('string(wp:post_type)', $node);

                    // 只处理文章和页面类型
                    if ($postType === 'post' || $postType === 'page') {
                        $this->processPost($xpath, $node, $postType);

                        if ($postType === 'post') {
                            $postCount++;
                        } else {
                            $pageCount++;
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('处理文章/页面项目时出错: ' . $e->getMessage(), ['exception' => $e]);
                }

                // 更新进度（第二阶段占50%进度，从50%开始）
                $progress = 50 + intval(($processedItems / max(1, $totalItems)) * 50);
                $this->importJob->update([
                    'progress' => $progress,
                    'message' => "第二阶段：处理文章和页面 ({$processedItems}/{$totalItems})",
                ]);
            }
        }

        $reader->close();
        Log::info('文章和页面处理完成，共处理文章: ' . $postCount . ' 篇，页面: ' . $pageCount . ' 个');
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
        Log::debug('处理XML项目');
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
        Log::debug('项目类型: ' . $postType);

        // 根据不同类型处理
        switch ($postType) {
            case 'post':
            case 'page':
                $this->processPost($xpath, $node, $postType);
                break;
            case 'attachment':
                if (!empty($this->options['import_attachments'])) {
                    $attachmentInfo = $this->processAttachment($xpath, $node);
                    if ($attachmentInfo && $attachmentInfo['media_id']) {
                        // 保存附件映射关系
                        $this->attachmentMap[$attachmentInfo['url']] = $attachmentInfo;
                        Log::debug('保存附件映射: ' . $attachmentInfo['url'] . ' => 媒体ID: ' . $attachmentInfo['media_id']);
                    }
                }
                break;
            default:
                Log::debug('忽略不支持的项目类型: ' . $postType);
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
        Log::debug('处理文章/页面');
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

        Log::debug('文章信息 - 标题: ' . $title . ', 状态: ' . $status . ', 类型: ' . $postType);

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

        // 替换内容中的附件链接
        if (!empty($content) && !empty($this->attachmentMap)) {
            $content = $this->replaceAttachmentLinks($content, $content_type);
        }
        if (!empty($excerpt) && !empty($this->attachmentMap)) {
            $excerpt = $this->replaceAttachmentLinks($excerpt, $content_type);
        }

        // 处理作者
        $authorId = $this->processAuthor($xpath, $node);

        // 处理分类与标签（多选）
        $categoryIds = $this->processCategories($xpath, $node);
        $tagIds = $this->processTags($xpath, $node);

        // 转换状态
        $statusMap = [
            'publish' => 'published',
            'draft' => 'draft',
            'private' => 'draft',
            'pending' => 'draft',
            'future' => 'draft',
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
                    Log::debug('覆盖模式：更新现有文章，ID: ' . $existingPost->id);
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
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                        }
                    }

                    // 更新分类/标签关联，采用 sync 覆盖式同步
                    if (!empty($categoryIds)) {
                        $existingPost->categories()->sync($categoryIds);
                    } else {
                        // 若无分类传入则清空
                        $existingPost->categories()->sync([]);
                    }
                    if (!empty($tagIds)) {
                        $existingPost->tags()->sync($tagIds);
                    } else {
                        $existingPost->tags()->sync([]);
                    }

                    Log::debug('文章更新完成，ID: ' . $existingPost->id);

                    return;

                case 'skip':
                default:
                    // 跳过模式：记录日志并跳过
                    Log::debug('跳过模式：跳过重复文章，标题: ' . $title);

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

        Log::debug('保存文章: ' . $post->title);
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
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Log::debug('文章作者关联已保存，文章ID: ' . $post->id . '，作者ID: ' . $authorId);
            }
        }

        // 保存文章分类/标签关联（覆盖式同步）
        if (!empty($categoryIds)) {
            $post->categories()->sync($categoryIds);
            Log::debug('文章分类已同步，文章ID: ' . $post->id . '，分类IDs: ' . implode(',', $categoryIds));
        } else {
            $post->categories()->sync([]);
        }
        if (!empty($tagIds)) {
            $post->tags()->sync($tagIds);
            Log::debug('文章标签已同步，文章ID: ' . $post->id . '，标签IDs: ' . implode(',', $tagIds));
        } else {
            $post->tags()->sync([]);
        }

        Log::debug('文章保存完成，ID: ' . $post->id);
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
                'sign' => $sign,
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
        Log::debug('处理作者: ' . $authorName);

        // 优先使用任务指定默认作者
        if (empty($authorName)) {
            Log::debug('使用默认作者ID: ' . var_export($this->defaultAuthorId, true));

            return $this->defaultAuthorId;
        }

        // 按导出的作者名查找现有普通用户
        $author = Author::where('username', $authorName)->first();
        if ($author) {
            Log::debug('找到现有用户: ' . $author->username . ' (ID: ' . $author->id . ')');

            return (int) $author->id;
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
    /**
     * 收集分类IDs（支持多个）
     *
     * 优先使用 nicename 作为 slug；无 nicename 时，使用翻译生成 slug。
     * 若已存在则复用；否则创建后返回ID。
     *
     * @return int[] 分类ID数组
     */
    protected function processCategories(DOMXPath $xpath, DOMNode $node): array
    {
        $ids = [];
        $nodes = $xpath->query('category[@domain="category"]', $node);
        if (!$nodes || $nodes->length === 0) {
            Log::debug('无分类信息');

            return $ids;
        }

        foreach ($nodes as $n) {
            $name = trim((string) $n->nodeValue);
            $nicename = '';
            if ($n instanceof \DOMElement) {
                $nicename = (string) $n->getAttribute('nicename');
            }
            if ($name === '') {
                continue;
            }

            // 确定slug：优先 nicename，否则翻译生成
            $slug = $nicename ?: $this->translateTitle($name);
            if (empty($slug)) {
                $slug = \Illuminate\Support\Str::slug($name) ?: ('category-' . time());
            }

            // 先按 slug 查找，找不到再按 name 查找
            $category = \app\model\Category::where('slug', $slug)->first();
            if (!$category) {
                $category = \app\model\Category::where('name', $name)->first();
            }

            if ($category) {
                $ids[] = (int) $category->id;
                Log::debug("复用分类: {$category->name} ({$category->id})");
                continue;
            }

            // 唯一 slug 处理
            $originalSlug = $slug;
            $suffix = 1;
            while (\app\model\Category::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $suffix;
                $suffix++;
            }

            try {
                $category = new \app\model\Category();
                $category->name = $name;
                $category->slug = $slug;
                $category->parent_id = null;
                $category->sort_order = 0;
                $category->created_at = date('Y-m-d H:i:s');
                $category->updated_at = date('Y-m-d H:i:s');
                $category->save();
                $ids[] = (int) $category->id;
                Log::debug('新分类创建完成，ID: ' . $category->id);
            } catch (\Exception $e) {
                Log::error('创建分类时出错: ' . $e->getMessage());
            }
        }

        // 去重
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids;
    }

    /**
     * 收集标签IDs（支持多个）
     *
     * 优先使用 nicename 作为 slug；无 nicename 时，使用翻译生成 slug。
     * 若已存在则复用；否则创建后返回ID。
     *
     * @return int[] 标签ID数组
     */
    protected function processTags(DOMXPath $xpath, DOMNode $node): array
    {
        $ids = [];
        $nodes = $xpath->query('category[@domain="post_tag"]', $node);
        if (!$nodes || $nodes->length === 0) {
            Log::debug('无标签信息');

            return $ids;
        }

        foreach ($nodes as $n) {
            $name = trim((string) $n->nodeValue);
            $nicename = '';
            if ($n instanceof \DOMElement) {
                $nicename = (string) $n->getAttribute('nicename');
            }
            if ($name === '') {
                continue;
            }

            // 确定slug：优先 nicename，否则翻译生成
            $slug = $nicename ?: $this->translateTitle($name);
            if (empty($slug)) {
                $slug = \Illuminate\Support\Str::slug($name) ?: ('tag-' . time());
            }

            // 先按 slug 查找，找不到再按 name 查找
            $tag = \app\model\Tag::where('slug', $slug)->first();
            if (!$tag) {
                $tag = \app\model\Tag::where('name', $name)->first();
            }

            if ($tag) {
                $ids[] = (int) $tag->id;
                Log::debug("复用标签: {$tag->name} ({$tag->id})");
                continue;
            }

            // 唯一 slug 处理
            $originalSlug = $slug;
            $suffix = 1;
            while (\app\model\Tag::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $suffix;
                $suffix++;
            }

            try {
                $tag = new \app\model\Tag();
                $tag->name = $name;
                $tag->slug = $slug;
                $tag->created_at = date('Y-m-d H:i:s');
                $tag->updated_at = date('Y-m-d H:i:s');
                $tag->save();
                $ids[] = (int) $tag->id;
                Log::debug('新标签创建完成，ID: ' . $tag->id);
            } catch (\Exception $e) {
                Log::error('创建标签时出错: ' . $e->getMessage());
            }
        }

        // 去重
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids;
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
                    return (int) $user->id;
                }
            }

            // 3) 回退：选取任意一个已有用户
            $any = Author::orderBy('id', 'asc')->first();
            if ($any) {
                return (int) $any->id;
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
     * @return array|null 返回附件信息，包含url、title和media_id（如果下载成功）
     */
    protected function processAttachment(DOMXPath $xpath, DOMNode $node): ?array
    {
        $title = $xpath->evaluate('string(title)', $node);
        $url = $xpath->evaluate('string(wp:attachment_url)', $node);

        Log::debug('处理附件 - 标题: ' . $title . ', URL: ' . $url);

        if (empty($url)) {
            Log::debug('附件URL为空，跳过');

            return null;
        }

        $attachmentInfo = [
            'url' => $url,
            'title' => $title,
            'media_id' => null,
        ];

        // 如果需要下载附件
        if (!empty($this->options['download_attachments'])) {
            $mediaData = $this->downloadAttachment($url, $title);
            if ($mediaData) {
                $attachmentInfo['media_id'] = $mediaData['id'] ?? null;
                $attachmentInfo['media_path'] = $mediaData['file_path'] ?? null;
            }
        }

        return $attachmentInfo;
    }

    /**
     * 下载附件
     *
     * @param string $url
     * @param string $title
     *
     * @return array|null 返回下载的媒体信息，失败返回null
     */
    protected function downloadAttachment(string $url, string $title): ?array
    {
        try {
            Log::debug('下载附件: ' . $url);

            // 使用MediaLibraryService下载远程文件
            $result = $this->mediaLibraryService->downloadRemoteFile(
                $url,
                $title,
                $this->defaultAuthorId,
                'admin'
            );

            if ($result['code'] === 0) {
                $media = $result['data'];
                Log::debug('附件下载成功，媒体ID: ' . ($media->id ?? '未知'));

                // 将Media对象转换为数组格式返回
                return [
                    'id' => $media->id ?? null,
                    'file_path' => $media->file_path ?? null,
                    'file_name' => $media->filename ?? null,
                    'file_size' => $media->file_size ?? null,
                    'mime_type' => $media->mime_type ?? null,
                    'title' => $media->original_name ?? $title,
                    'url' => '/uploads/' . ($media->file_path ?? ''),
                ];
            } else {
                Log::warning('附件下载失败: ' . $result['msg']);

                return null;
            }
        } catch (\Exception $e) {
            Log::error('下载附件时出错: ' . $e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * 将HTML转换为Markdown
     *
     * @param string $html
     * @param array  $config
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
                    Log::info('删除导入临时文件: ' . $filePath);
                }
            }

            Log::info('导入目录清理完成: ' . $importDir);
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
            ],
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
        $excerpt = strip_tags((string) $html);

        // 截取前200个字符作为摘要
        return mb_substr($excerpt, 0, 200, 'UTF-8');
    }

    /**
     * 替换内容中的附件链接
     *
     * @param string $content     原始内容
     * @param string $contentType 内容类型（html/markdown）
     *
     * @return string 替换后的内容
     */
    protected function replaceAttachmentLinks(string $content, string $contentType): string
    {
        if (empty($content) || empty($this->attachmentMap)) {
            return $content;
        }

        Log::debug('开始替换附件链接，内容类型: ' . $contentType . ', 附件数量: ' . count($this->attachmentMap));

        // 首先，创建基础URL到新URL的映射表
        $baseUrlToNewUrl = [];
        foreach ($this->attachmentMap as $originalUrl => $attachmentInfo) {
            if (!empty($attachmentInfo['media_id']) && !empty($attachmentInfo['media_path'])) {
                $baseUrl = $originalUrl;

                // 获取基础URL（去除尺寸参数）
                $extractedBaseUrl = $this->getBaseAttachmentUrl($originalUrl);
                if ($extractedBaseUrl) {
                    $baseUrl = $extractedBaseUrl;
                }

                $baseUrlToNewUrl[$baseUrl] = '/uploads/' . $attachmentInfo['media_path'];
            }
        }

        // 先处理所有带尺寸参数的URL变体
        if ($contentType === 'html') {
            $content = $this->replaceSizeVariantUrlsInHtml($content, $baseUrlToNewUrl);
        } else {
            $content = $this->replaceSizeVariantUrlsInMarkdown($content, $baseUrlToNewUrl);
        }

        // 然后处理标准URL
        foreach ($this->attachmentMap as $originalUrl => $attachmentInfo) {
            if (empty($attachmentInfo['media_id']) || empty($attachmentInfo['media_path'])) {
                continue;
            }

            // 生成新的媒体URL
            $newUrl = '/uploads/' . $attachmentInfo['media_path'];

            // 根据内容类型进行不同的替换策略
            if ($contentType === 'html') {
                // HTML内容替换
                $patterns = [
                    // 替换img标签的src属性
                    '/(<img[^>]*src=["\'])' . preg_quote($originalUrl, '/') . '(["\'][^>]*>)/i',
                    // 替换a标签的href属性
                    '/(<a[^>]*href=["\'])' . preg_quote($originalUrl, '/') . '(["\'][^>]*>)/i',
                    // 替换其他可能的属性
                    '/(src=["\'])' . preg_quote($originalUrl, '/') . '(["\'])/i',
                    '/(href=["\'])' . preg_quote($originalUrl, '/') . '(["\'])/i',
                ];

                foreach ($patterns as $pattern) {
                    $replacement = '${1}' . $newUrl . '${2}';
                    $content = preg_replace($pattern, $replacement, $content);
                }
            } else {
                // Markdown内容替换
                $patterns = [
                    // 替换Markdown图片链接
                    '/!\[([^\]]*)\]\(' . preg_quote($originalUrl, '/') . '\)/i',
                    // 替换Markdown普通链接
                    '/\[([^\]]*)\]\(' . preg_quote($originalUrl, '/') . '\)/i',
                    // 替换直接URL
                    '/\b' . preg_quote($originalUrl, '/') . '\b/i',
                ];

                foreach ($patterns as $pattern) {
                    if (str_contains($pattern, '!\[')) {
                        // 图片链接替换
                        $replacement = '![${1}](' . $newUrl . ')';
                    } elseif (str_contains($pattern, '\[')) {
                        // 普通链接替换
                        $replacement = '[${1}](' . $newUrl . ')';
                    } else {
                        // 直接URL替换
                        $replacement = $newUrl;
                    }
                    $content = preg_replace($pattern, $replacement, $content);
                }
            }

            Log::debug('替换附件链接: ' . $originalUrl . ' => ' . $newUrl);
        }

        return $content;
    }

    /**
     * 在HTML内容中替换带尺寸参数的URL变体
     *
     * @param string $content         HTML内容
     * @param array  $baseUrlToNewUrl 基础URL到新URL的映射
     *
     * @return string 替换后的HTML内容
     */
    protected function replaceSizeVariantUrlsInHtml(string $content, array $baseUrlToNewUrl): string
    {
        // 匹配带尺寸参数的URL模式
        $sizePattern = '/(-\d+x\d+)(\.\w+)/i';

        // 提取内容中所有可能的URL
        preg_match_all('/src=["\']([^"\']*)["\']|href=["\']([^"\']*)["\']/', $content, $matches, PREG_SET_ORDER);

        // 处理每个匹配到的URL
        foreach ($matches as $match) {
            $url = $match[1] ?? ($match[2] ?? '');
            if (empty($url)) {
                continue;
            }

            // 检查URL是否带有尺寸参数
            if (preg_match($sizePattern, $url)) {
                // 获取基础URL
                $baseUrl = $this->getBaseAttachmentUrl($url);
                if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                    $newUrl = $baseUrlToNewUrl[$baseUrl];

                    // 构建替换模式
                    $pattern = '/(src=["\']|href=["\'])' . preg_quote($url, '/') . '(["\'])/i';
                    $replacement = '${1}' . $newUrl . '${2}';

                    // 执行替换
                    $content = preg_replace($pattern, $replacement, $content);
                    Log::debug('替换带尺寸参数的URL: ' . $url . ' => ' . $newUrl);
                }
            }
        }

        return $content;
    }

    /**
     * 在Markdown内容中替换带尺寸参数的URL变体
     *
     * @param string $content         Markdown内容
     * @param array  $baseUrlToNewUrl 基础URL到新URL的映射
     *
     * @return string 替换后的Markdown内容
     */
    protected function replaceSizeVariantUrlsInMarkdown(string $content, array $baseUrlToNewUrl): string
    {
        // 匹配带尺寸参数的URL模式 - 优化匹配逻辑，确保能捕获所有变体
        $sizePattern = '/-\d+x\d+\.\w+$/i';

        Log::debug('进入replaceSizeVariantUrlsInMarkdown方法，baseUrlToNewUrl映射数: ' . count($baseUrlToNewUrl));
        // 使用正则表达式检测内容中是否包含任意带尺寸参数的URL
        preg_match('/-\d+x\d+\.\w+/i', $content, $matches);
        Log::debug('内容中是否包含带尺寸参数的URL: ' . (!empty($matches) ? '是' : '否'));
        if (!empty($matches)) {
            Log::debug('匹配到的第一个带尺寸参数的URL片段: ' . $matches[0]);
        }

        // 提取内容中所有可能的URL（图片链接、普通链接和直接URL）
        // 匹配图片链接 ![alt](url)
        preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/i', $content, $imageMatches, PREG_SET_ORDER);
        // 匹配普通链接 [text](url)
        preg_match_all('/\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/i', $content, $linkMatches, PREG_SET_ORDER);

        Log::debug('图片链接数量: ' . count($imageMatches) . ', 普通链接数量: ' . count($linkMatches));

        // 处理图片链接
        foreach ($imageMatches as $match) {
            $fullMatch = $match[0];
            $altText = $match[1];
            $url = $match[2];

            Log::debug('检查图片URL: ' . $url);
            // 检查URL是否带有尺寸参数
            if (preg_match($sizePattern, $url)) {
                Log::debug('发现带尺寸参数的图片URL: ' . $url);
                // 获取基础URL
                $baseUrl = $this->getBaseAttachmentUrl($url);
                Log::debug('提取的基础URL: ' . ($baseUrl ?: 'null'));
                if ($baseUrl) {
                    Log::debug('基础URL是否在映射表中: ' . (isset($baseUrlToNewUrl[$baseUrl]) ? '是' : '否'));
                    if (isset($baseUrlToNewUrl[$baseUrl])) {
                        $newUrl = $baseUrlToNewUrl[$baseUrl];

                        // 构建替换后的内容
                        $replacement = '![' . $altText . '](' . $newUrl . ')';

                        // 执行替换
                        $content = str_replace($fullMatch, $replacement, $content);
                        Log::debug('替换带尺寸参数的图片链接: ' . $url . ' => ' . $newUrl);
                    }
                }
            }
        }

        // 处理普通链接
        foreach ($linkMatches as $match) {
            $fullMatch = $match[0];
            $linkText = $match[1];
            $url = $match[2];

            Log::debug('检查普通链接URL: ' . $url);
            // 检查URL是否带有尺寸参数
            if (preg_match($sizePattern, $url)) {
                Log::debug('发现带尺寸参数的普通链接URL: ' . $url);
                // 获取基础URL
                $baseUrl = $this->getBaseAttachmentUrl($url);
                Log::debug('提取的基础URL: ' . ($baseUrl ?: 'null'));
                if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                    $newUrl = $baseUrlToNewUrl[$baseUrl];

                    // 构建替换后的内容
                    $replacement = '[' . $linkText . '](' . $newUrl . ')';

                    // 执行替换
                    $content = str_replace($fullMatch, $replacement, $content);
                    Log::debug('替换带尺寸参数的链接: ' . $url . ' => ' . $newUrl);
                }
            }
        }

        // 处理独立的URL（不包含在Markdown语法中的直接URL）
        // 使用更简单的正则表达式匹配独立URL
        $urlPattern = '/https?:\/\/[^\s\)]+\.(?:jpe?g|png|gif|bmp|webp|svg|ico|tiff|tif)(-\d+x\d+)/i';
        if (preg_match_all($urlPattern, $content, $directUrlMatches)) {
            Log::debug('发现独立URL数量: ' . count($directUrlMatches[0]));
            foreach ($directUrlMatches[0] as $matchedUrl) {
                Log::debug('检查独立URL: ' . $matchedUrl);
                // 获取基础URL
                $baseUrl = $this->getBaseAttachmentUrl($matchedUrl);
                Log::debug('提取的基础URL: ' . ($baseUrl ?: 'null'));
                if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                    $newUrl = $baseUrlToNewUrl[$baseUrl];

                    // 执行替换
                    $content = str_replace($matchedUrl, $newUrl, $content);
                    Log::debug('替换带尺寸参数的直接URL: ' . $matchedUrl . ' => ' . $newUrl);
                }
            }
        }

        return $content;
    }

    /**
     * 获取附件的基础URL（移除尺寸参数）
     *
     * @param string $url 原始URL
     *
     * @return string|null 基础URL，如果无法提取则返回null
     */
    protected function getBaseAttachmentUrl(string $url): ?string
    {
        // 匹配WordPress带尺寸参数的图片URL模式
        // 支持完整URL和相对路径两种格式
        // 完整URL：https://www.biliwind.com/wp-content/uploads/2025/05/image-1746177998-1024x526.png
        // 相对路径：/wp-content/uploads/2025/05/image-1746177998-1024x526.png

        // 简化的模式，专注于匹配URL末尾的-数字x数字.扩展名格式
        $simplePattern = '/-\d+x\d+(\.\w+)$/';

        // 首先尝试在完整URL上应用简单替换模式（优先返回完整URL）
        if (preg_match($simplePattern, $url, $simpleMatches)) {
            $baseUrl = preg_replace($simplePattern, $simpleMatches[1], $url);
            Log::debug("使用简单替换提取完整URL基础URL: $url => $baseUrl");

            return $baseUrl;
        }

        // 如果完整URL匹配失败，检查是否是带域名的URL
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['scheme']) && isset($parsedUrl['host']) && isset($parsedUrl['path'])) {
            // 提取路径部分
            $path = $parsedUrl['path'];
            // 尝试在路径部分应用简单替换模式
            if (preg_match($simplePattern, $path, $simpleMatches)) {
                $basePath = preg_replace($simplePattern, $simpleMatches[1], $path);
                // 构建完整的基础URL
                $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $basePath;
                Log::debug("使用路径部分替换构建完整基础URL: $url => $baseUrl");

                return $baseUrl;
            }
        }

        // 最后，尝试处理相对路径或其他情况
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null) {
            $path = $url;
        }
        if (preg_match($simplePattern, $path, $simpleMatches)) {
            $basePath = preg_replace($simplePattern, $simpleMatches[1], $path);
            Log::debug("使用简单替换提取路径部分基础URL: $path => $basePath");

            return $basePath;
        }

        Log::debug("无法提取基础URL: $url");

        return null;
    }
}
