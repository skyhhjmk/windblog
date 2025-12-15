<?php

namespace app\service;

use app\model\Admin;
use app\model\Author;
use app\model\Category;
use app\model\Comment;
use app\model\ImportJob;
use app\model\Media;
use app\model\Post;
use app\model\Tag;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use Illuminate\Support\Str;
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
     * WordPress文章ID到新文章ID的映射关系
     *
     * @var array
     */
    protected array $postIdMap = [];

    /**
     * WordPress评论ID到新评论ID的映射关系
     *
     * @var array
     */
    protected array $commentIdMap = [];

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
            $this->importJob->update([
                'progress' => 0,
                'message' => '开始执行导入任务，修复XML文件命名空间',
            ]);

            $fixedXmlFile = $this->fixXmlNamespaces($this->importJob->file_path);
            if (!$fixedXmlFile) {
                throw new Exception('XML文件修复失败');
            }

            // 计算项目总数
            $this->importJob->update([
                'progress' => 5,
                'message' => 'XML文件修复完成，开始计算项目总数',
            ]);

            $totalItems = $this->countItems($fixedXmlFile);
            if ($totalItems === 0) {
                throw new Exception('XML文件中没有找到任何项目');
            }

            Log::info('开始处理XML项目，总数: ' . $totalItems);
            $this->importJob->update([
                'progress' => 10,
                'message' => '项目总数计算完成，开始处理XML项目，总数: ' . $totalItems,
            ]);

            // 第一阶段：处理所有附件，建立完整的附件映射关系
            $this->processAllAttachments($fixedXmlFile, $totalItems);

            // 第二阶段：处理所有文章和页面，使用完整的附件映射关系
            $this->processAllPosts($fixedXmlFile, $totalItems);

            // 清空附件映射关系
            $this->attachmentMap = [];
            Log::debug('清空附件映射关系');

            // 第三阶段：处理评论（如果启用）
            if (!empty($this->options['import_comments'])) {
                $this->processAllComments($fixedXmlFile, $totalItems);
            } else {
                // 如果不处理评论，直接更新进度到100%
                $this->importJob->update([
                    'progress' => 100,
                    'message' => '导入任务完成，跳过评论处理',
                ]);
            }

            // 清理临时文件（所有处理完成后）
            if ($fixedXmlFile !== $this->importJob->file_path && file_exists($fixedXmlFile)) {
                unlink($fixedXmlFile);
                Log::info('删除临时修复文件: ' . $fixedXmlFile);
            }

            // 清空映射关系
            $this->postIdMap = [];
            $this->commentIdMap = [];
            Log::debug('清空文章和评论映射关系');

            Log::info('WordPress XML导入任务处理完成: ' . $this->importJob->name);

            return true;

        } catch (Exception $e) {
            Log::error('导入任务执行错误: ' . $e->getMessage(), ['exception' => $e]);
            $this->importJob->update([
                'status' => 'failed',
                'progress' => 0,
                'message' => '导入任务执行错误: ' . $e->getMessage(),
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
        } catch (Exception $e) {
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
            throw new Exception('无法打开XML文件: ' . $xmlFile);
        }

        $processedItems = 0;
        $attachmentCount = 0;
        $attachmentsToProcess = [];

        // 第一遍：收集所有附件信息
        Log::info('开始收集所有附件信息');
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $processedItems++;

                try {
                    $doc = new DOMDocument();
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
                        $title = $xpath->evaluate('string(title)', $node);
                        $url = $xpath->evaluate('string(wp:attachment_url)', $node);
                        if (!empty($url)) {
                            $attachmentsToProcess[] = [
                                'title' => $title,
                                'url' => $url,
                            ];
                            $attachmentCount++;
                        }
                    }
                } catch (Exception $e) {
                    Log::error('收集附件信息时出错: ' . $e->getMessage(), ['exception' => $e]);
                }

                // 更新进度（第一阶段区10%进度用于收集附件）
                $progress = intval(($processedItems / max(1, $totalItems)) * 10);
                $this->importJob->update([
                    'progress' => $progress,
                    'message' => "第一阶段：收集附件信息 ({$processedItems}/{$totalItems})",
                ]);
            }
        }

        $reader->close();
        Log::info('附件信息收集完成，共收集附件: ' . $attachmentCount . ' 个');

        // 如果没有附件需要处理，直接返回
        if (empty($attachmentsToProcess)) {
            Log::info('没有附件需要处理');
            $this->importJob->update([
                'progress' => 30,
                'message' => '第一阶段：附件处理完成 (0/0)',
            ]);

            return;
        }

        // 第二遍：使用curl多线程处理所有附件
        Log::info('开始多线程处理附件，共 ' . count($attachmentsToProcess) . ' 个');
        $processedAttachments = 0;
        $batchSize = 10; // 每次并行处理的附件数量
        $batches = array_chunk($attachmentsToProcess, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchResults = [];

            // 使用curl多线程处理当前批次
            $batchResults = $this->downloadAttachmentsInParallel($batch);

            // 处理批次结果
            foreach ($batchResults as $result) {
                if ($result && !empty($result['id']) && !empty($result['original_url'])) {
                    // 保存附件映射关系
                    $this->attachmentMap[$result['original_url']] = $result;
                    Log::debug('保存附件映射: ' . $result['original_url'] . ' => 媒体ID: ' . $result['id']);
                }
            }

            // 更新处理进度
            $processedAttachments += count($batch);
            $batchProgress = 10 + intval(($processedAttachments / max(1, $attachmentCount)) * 20);
            $this->importJob->update([
                'progress' => $batchProgress,
                'message' => "第一阶段：多线程处理附件 ({$processedAttachments}/{$attachmentCount})",
            ]);

            Log::info('批次 ' . ($batchIndex + 1) . '/' . $totalBatches . ' 处理完成，已处理附件: ' . $processedAttachments . '/' . $attachmentCount);
        }

        Log::info('附件处理完成，共处理附件: ' . count($this->attachmentMap) . ' 个');
        $this->importJob->update([
            'progress' => 30,
            'message' => "第一阶段：附件处理完成 ({$processedAttachments}/{$attachmentCount})",
        ]);
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
            throw new Exception('无法打开XML文件: ' . $xmlFile);
        }

        $processedItems = 0;
        $postCount = 0;
        $pageCount = 0;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $processedItems++;

                try {
                    $doc = new DOMDocument();
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
                } catch (Exception $e) {
                    Log::error('处理文章/页面项目时出错: ' . $e->getMessage(), ['exception' => $e]);
                }

                // 更新进度（第二阶段區50%进度，从30%到80%）
                $progress = 30 + intval(($processedItems / max(1, $totalItems)) * 50);
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
        $doc = new DOMDocument();
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
            default:
                // 默认使用markdown格式
                $content_type = 'markdown';
                // 将HTML内容转换为Markdown
                if (!empty($content)) {
                    $content = $this->convertHtmlToMarkdown($content);
                }

                if (!empty($excerpt)) {
                    $excerpt = $this->convertHtmlToMarkdown($excerpt);
                }
                break;
        }

        // 替换内容中的附件链接
        $referencedMediaIds = [];
        if (!empty($content) && !empty($this->attachmentMap)) {
            [$content, $contentReferencedMediaIds] = $this->replaceAttachmentLinks($content, $content_type);
            $referencedMediaIds = array_merge($referencedMediaIds, $contentReferencedMediaIds);
            // 格式化markdown内容中的图片链接
            if ($content_type === 'markdown') {
                $content = $this->formatMarkdownImages($content);
            }
        }
        if (!empty($excerpt) && !empty($this->attachmentMap)) {
            [$excerpt, $excerptReferencedMediaIds] = $this->replaceAttachmentLinks($excerpt, $content_type);
            $referencedMediaIds = array_merge($referencedMediaIds, $excerptReferencedMediaIds);
            // 格式化markdown摘要中的图片链接
            if ($content_type === 'markdown') {
                $excerpt = $this->formatMarkdownImages($excerpt);
            }
        }
        // 去重被引用的媒体ID
        $referencedMediaIds = array_unique($referencedMediaIds);

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
                    $slug = Str::slug($translatedTitle);

                    // 如果翻译后的slug仍然为空，则使用原文
                    if (empty($slug)) {
                        $slug = Str::slug($title);
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
        // 检查是否已存在相同slug的文章（作为重复判断依据）
        $existingPost = null;
        if ($slug) {
            $existingPost = Post::where('slug', $slug)->first();
        }

        // 根据重复处理模式决定如何处理
        $duplicateMode = $this->options['duplicate_mode'] ?? 'skip';

        // 如果存在相同slug的文章
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
                    $existingPost->updated_at = utc_now_string('Y-m-d H:i:s');
                    $existingPost->save();

                    // 更新作者关联
                    if ($authorId) {
                        // 获取作者模型实例
                        $author = Author::find($authorId);
                        if ($author) {
                            // 使用attach方法更新文章作者关联
                            // 先清除现有关联
                            $existingPost->authors()->detach();
                            // 再添加新的关联，并设置额外字段
                            $existingPost->authors()->attach($author, [
                                'is_primary' => 1,
                                'created_at' => utc_now_string('Y-m-d H:i:s'),
                                'updated_at' => utc_now_string('Y-m-d H:i:s'),
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

                    // 保存WordPress文章ID到更新后文章ID的映射（以便评论导入）
                    if (!empty($wpPostId)) {
                        $this->postIdMap[$wpPostId] = $existingPost->id;
                        Log::debug('保存覆盖文章的ID映射: WP ID ' . $wpPostId . ' => 现有ID ' . $existingPost->id);
                    }

                    return;

                case 'skip':
                default:
                    // 跳过模式：记录日志并跳过，但要保存ID映射以便导入评论
                    Log::debug('跳过模式：跳过重复文章，标题: ' . $title);

                    // 保存WordPress文章ID到现有文章ID的映射（以便评论导入）
                    if (!empty($wpPostId) && $existingPost) {
                        $this->postIdMap[$wpPostId] = $existingPost->id;
                        Log::debug('保存跳过文章的ID映射: WP ID ' . $wpPostId . ' => 现有ID ' . $existingPost->id);
                    }

                    return;
            }
        }

        // 创建新文章前，确保slug唯一，避免数据库唯一约束冲突
        if (!empty($slug)) {
            $originalSlug = $slug;
            $suffix = 1;
            while (Post::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $suffix;
                $suffix++;
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
        $post->updated_at = utc_now_string('Y-m-d H:i:s');

        Log::debug('保存文章: ' . $post->title);
        try {
            $post->save();
        } catch (Throwable $e) {
            // 如果是slug唯一约束冲突，回退到使用已有文章并建立映射
            $message = $e->getMessage();
            if (strpos($message, 'posts_slug_key') !== false || strpos($message, 'SQLSTATE[23505]') !== false) {
                $existing = Post::where('slug', $slug)->first();
                if ($existing) {
                    Log::warning('检测到slug冲突，使用现有文章并建立映射。slug=' . $slug . '，现有ID=' . $existing->id);
                    if (!empty($wpPostId)) {
                        $this->postIdMap[$wpPostId] = $existing->id;
                    }

                    // 更新现有文章引用的媒体
                    $this->updateMediaReferences($existing->id, $referencedMediaIds);

                    return; // 使用现有文章，后续评论将映射到该文章
                }
            }
            throw $e;
        }

        // 保存WordPress文章ID到新文章ID的映射
        if (!empty($wpPostId)) {
            $this->postIdMap[$wpPostId] = $post->id;
            Log::debug('保存文章ID映射: WP ID ' . $wpPostId . ' => 新ID ' . $post->id);
        }

        // 保存文章作者关联
        if ($authorId) {
            // 获取作者模型实例
            $author = Author::find($authorId);
            if ($author) {
                // 使用attach方法保存文章作者关联
                $post->authors()->attach($author, [
                    'is_primary' => 1,
                    'created_at' => utc_now_string('Y-m-d H:i:s'),
                    'updated_at' => utc_now_string('Y-m-d H:i:s'),
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

        // 更新媒体引用计数
        $this->updateMediaReferences($post->id, $referencedMediaIds);

        Log::debug('文章保存完成，ID: ' . $post->id);
    }

    /**
     * 更新媒体引用计数
     *
     * @param int   $postId             文章ID
     * @param array $referencedMediaIds 被引用的媒体ID列表
     *
     * @return void
     */
    protected function updateMediaReferences(int $postId, array $referencedMediaIds): void
    {
        if (empty($referencedMediaIds)) {
            return;
        }

        Log::debug('更新媒体引用计数，文章ID: ' . $postId . ', 媒体ID列表: ' . implode(',', $referencedMediaIds));

        // 遍历被引用的媒体ID
        foreach ($referencedMediaIds as $mediaId) {
            try {
                // 获取媒体实例
                $media = Media::find($mediaId);
                if (!$media) {
                    Log::warning('媒体ID不存在: ' . $mediaId);
                    continue;
                }

                // 更新媒体引用计数
                $media->addPostReference($postId);
                $media->save();

                Log::debug('更新媒体引用计数成功，媒体ID: ' . $mediaId . ', 文章ID: ' . $postId);
            } catch (Exception $e) {
                Log::error('更新媒体引用计数失败，媒体ID: ' . $mediaId . ', 错误: ' . $e->getMessage());
            }
        }
    }

    /**
     * 翻译标题（使用SlugTranslateService）
     *
     * @param string $title
     *
     * @return string
     * @throws Throwable
     */
    protected function translateTitle(string $title): string
    {
        try {
            // 使用SlugTranslateService
            $service = new SlugTranslateService();

            // 从导入配置中获取翻译模式和AI选择
            $mode = $this->options['slug_translate_mode'] ?? blog_config('slug_translate_mode', 'auto', true);
            $aiSelection = $this->options['slug_translate_ai_selection'] ?? blog_config('slug_translate_ai_selection', '', true);

            $result = $service->translate($title, [
                'mode' => $mode,
                'ai_selection' => $aiSelection ?: null,
            ]);

            if ($result !== null) {
                return $result;
            }

            // 如果翻译失败，回退到格式化原标题
            return $this->formatTitleAsSlug($title);
        } catch (Exception $e) {
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
            if ($n instanceof DOMElement) {
                $nicename = (string) $n->getAttribute('nicename');
            }
            if ($name === '') {
                continue;
            }

            // 确定slug：优先 nicename，否则翻译生成
            $slug = $nicename ?: $this->translateTitle($name);
            if (empty($slug)) {
                $slug = Str::slug($name) ?: ('category-' . time());
            }

            // 先按 slug 查找，找不到再按 name 查找
            $category = Category::where('slug', $slug)->first();
            if (!$category) {
                $category = Category::where('name', $name)->first();
            }

            if ($category) {
                $ids[] = (int) $category->id;
                Log::debug("复用分类: {$category->name} ({$category->id})");
                continue;
            }

            // 唯一 slug 处理
            $originalSlug = $slug;
            $suffix = 1;
            while (Category::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $suffix;
                $suffix++;
            }

            try {
                $category = new Category();
                $category->name = $name;
                $category->slug = $slug;
                $category->parent_id = null;
                $category->sort_order = 0;
                $category->created_at = utc_now_string('Y-m-d H:i:s');
                $category->updated_at = utc_now_string('Y-m-d H:i:s');
                $category->save();
                $ids[] = (int) $category->id;
                Log::debug('新分类创建完成，ID: ' . $category->id);
            } catch (Exception $e) {
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
            if ($n instanceof DOMElement) {
                $nicename = (string) $n->getAttribute('nicename');
            }
            if ($name === '') {
                continue;
            }

            // 确定slug：优先 nicename，否则翻译生成
            $slug = $nicename ?: $this->translateTitle($name);
            if (empty($slug)) {
                $slug = Str::slug($name) ?: ('tag-' . time());
            }

            // 先按 slug 查找，找不到再按 name 查找
            $tag = Tag::where('slug', $slug)->first();
            if (!$tag) {
                $tag = Tag::where('name', $name)->first();
            }

            if ($tag) {
                $ids[] = (int) $tag->id;
                Log::debug("复用标签: {$tag->name} ({$tag->id})");
                continue;
            }

            // 唯一 slug 处理
            $originalSlug = $slug;
            $suffix = 1;
            while (Tag::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $suffix;
                $suffix++;
            }

            try {
                $tag = new Tag();
                $tag->name = $name;
                $tag->slug = $slug;
                $tag->created_at = utc_now_string('Y-m-d H:i:s');
                $tag->updated_at = utc_now_string('Y-m-d H:i:s');
                $tag->save();
                $ids[] = (int) $tag->id;
                Log::debug('新标签创建完成，ID: ' . $tag->id);
            } catch (Exception $e) {
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
        } catch (Throwable $e) {
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
     * 使用curl多线程并行下载附件
     *
     * @param array $attachments 附件列表
     *
     * @return array 下载结果列表
     */
    protected function downloadAttachmentsInParallel(array $attachments): array
    {
        $results = [];

        // 串行处理，避免curl_multi的复杂逻辑
        foreach ($attachments as $attachment) {
            $results[] = $this->downloadAttachment($attachment['url'], $attachment['title']);
        }

        return $results;
    }

    /**
     * 下载附件
     *
     * @param string $url
     * @param string $title
     * @param int $maxRetries 最大重试次数
     * @param int $retryDelay 重试延迟（毫秒）
     *
     * @return array|null 返回下载的媒体信息，失败返回null
     */
    protected function downloadAttachment(string $url, string $title, int $maxRetries = 3, int $retryDelay = 1000): ?array
    {
        $attempts = 0;
        $lastError = null;
        $startTime = microtime(true);

        Log::info('开始下载附件: ' . $url . ', 标题: ' . $title);

        // 获取基础URL（去除尺寸参数）
        $baseUrl = $this->getBaseAttachmentUrl($url);
        $downloadUrls = [];

        // 如果URL带有尺寸参数，先尝试使用基础URL，再回退到原始URL
        if ($baseUrl && $baseUrl !== $url) {
            $downloadUrls[] = $baseUrl;
            $downloadUrls[] = $url;
        } else {
            $downloadUrls[] = $url;
        }

        // 修复重试逻辑：当$attempts < $maxRetries时继续重试，确保不超过最大重试次数
        while ($attempts < $maxRetries) {
            $attempts++;
            $attemptStartTime = microtime(true);

            // 尝试使用所有可能的URL下载
            foreach ($downloadUrls as $downloadUrl) {
                try {
                    Log::debug('下载附件 (尝试 ' . $attempts . '/' . $maxRetries . '): ' . $downloadUrl);

                    // 使用MediaLibraryService下载远程文件
                    $result = $this->mediaLibraryService->downloadRemoteFile(
                        $downloadUrl,
                        $title,
                        $this->defaultAuthorId,
                        'admin'
                    );

                    $attemptDuration = round((microtime(true) - $attemptStartTime) * 1000, 2);

                    if ($result['code'] === 0) {
                        $media = $result['data'];
                        $totalDuration = round((microtime(true) - $startTime) * 1000, 2);
                        Log::info('附件下载成功 (尝试 ' . $attempts . '/' . $maxRetries . '), 耗时: ' . $totalDuration . 'ms, 媒体ID: ' . ($media->id ?? '未知') . ', URL: ' . $downloadUrl);

                        // 不需要记录外部URL引用信息，只记录文章引用

                        // 将Media对象转换为数组格式返回
                        $mediaInfo = [
                            'id' => $media->id ?? null,
                            'file_path' => $media->file_path ?? null,
                            'file_name' => $media->filename ?? null,
                            'file_size' => $media->file_size ?? null,
                            'mime_type' => $media->mime_type ?? null,
                            'title' => $media->original_name ?? $title,
                            'url' => '/uploads/' . ($media->file_path ?? ''),
                            'original_url' => $url,
                            'download_attempts' => $attempts,
                            'download_duration' => $totalDuration,
                        ];

                        // 记录详细的媒体信息
                        Log::debug('附件详细信息: ' . json_encode($mediaInfo, JSON_UNESCAPED_UNICODE));

                        return $mediaInfo;
                    } else {
                        $lastError = $result['msg'];
                        Log::warning('附件下载失败 (尝试 ' . $attempts . '/' . $maxRetries . '), 耗时: ' . $attemptDuration . 'ms, 错误: ' . $result['msg'] . ', URL: ' . $downloadUrl);
                    }
                } catch (Exception $e) {
                    $lastError = $e->getMessage();
                    $attemptDuration = round((microtime(true) - $attemptStartTime) * 1000, 2);
                    Log::error('下载附件时出错 (尝试 ' . $attempts . '/' . $maxRetries . '), 耗时: ' . $attemptDuration . 'ms, 错误: ' . $e->getMessage() . ', URL: ' . $downloadUrl, ['exception' => $e]);
                }
            }

            // 如果不是最后一次尝试，等待一段时间后重试
            if ($attempts < $maxRetries) {
                Log::debug('等待 ' . $retryDelay . 'ms 后重试下载附件: ' . $url);
                usleep($retryDelay * 1000); // 转换为微秒
            }
        }

        // 所有尝试都失败
        $totalDuration = round((microtime(true) - $startTime) * 1000, 2);
        Log::error('附件下载失败，已重试 ' . $maxRetries . ' 次, 总耗时: ' . $totalDuration . 'ms, URL: ' . $url . ', 最后错误: ' . $lastError);

        // 记录下载失败的附件信息，便于后续分析
        Log::info('下载失败的附件信息: ' . json_encode([
                'url' => $url,
                'title' => $title,
                'max_retries' => $maxRetries,
                'last_error' => $lastError,
                'total_duration' => $totalDuration,
            ], JSON_UNESCAPED_UNICODE));

        // 将失败媒体记录存储到import job的options字段中
        $options = json_decode($this->importJob->options, true) ?? [];

        // 确保failed_media数组存在
        if (!isset($options['failed_media'])) {
            $options['failed_media'] = [];
        }

        // 添加失败媒体记录
        $options['failed_media'][] = [
            'url' => $url,
            'title' => $title,
            'error' => $lastError,
            'retry_count' => 0,
            'created_at' => time(),
        ];

        // 更新import job的options字段
        $this->importJob->options = json_encode($options, JSON_UNESCAPED_UNICODE);
        $this->importJob->save();

        Log::info('已将失败媒体记录存储到任务选项中，URL: ' . $url);

        return null;
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
        } catch (Exception $e) {
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
     * @return array [替换后的内容, 被引用的媒体ID列表]
     */
    protected function replaceAttachmentLinks(string $content, string $contentType): array
    {
        if (empty($content) || empty($this->attachmentMap)) {
            return [$content, []];
        }

        Log::debug('开始替换附件链接，内容类型: ' . $contentType . ', 附件数量: ' . count($this->attachmentMap));

        // 创建URL映射表，包含原始URL和基础URL
        $urlMap = [];
        $baseUrlToNewUrl = [];
        $validUrlMap = [];
        $referencedMediaIds = [];

        // 保存原始内容的副本，用于检查URL是否存在
        $contentCopy = $content;

        // 首先，创建完整的URL映射关系，并验证新URL的有效性
        foreach ($this->attachmentMap as $originalUrl => $attachmentInfo) {
            // 修复键名错误：使用id而不是media_id，使用file_path而不是media_path
            if (empty($attachmentInfo['id']) || empty($attachmentInfo['file_path'])) {
                continue;
            }

            $newUrl = '/uploads/' . $attachmentInfo['file_path'];
            $filePath = public_path($newUrl);

            // 验证文件是否存在
            if (file_exists($filePath)) {
                // 先获取原始URL的基础URL（去除尺寸参数）
                $baseUrl = $this->getBaseAttachmentUrl($originalUrl);

                // 如果获取到基础URL，将基础URL映射到新URL
                if ($baseUrl) {
                    $baseUrlToNewUrl[$baseUrl] = [
                        'new_url' => $newUrl,
                        'media_id' => $attachmentInfo['id'],
                    ];
                }

                // 同时将原始URL映射到新URL，作为回退
                $urlMap[$originalUrl] = [
                    'new_url' => $newUrl,
                    'media_id' => $attachmentInfo['id'],
                ];
                $validUrlMap[$originalUrl] = [
                    'new_url' => $newUrl,
                    'media_id' => $attachmentInfo['id'],
                ];

                // 如果原始URL没有尺寸参数，直接将原始URL作为基础URL
                if (!$baseUrl) {
                    $baseUrlToNewUrl[$originalUrl] = [
                        'new_url' => $newUrl,
                        'media_id' => $attachmentInfo['id'],
                    ];
                }
            } else {
                Log::warning('URL映射无效: ' . $originalUrl . ' => ' . $newUrl . ', 文件不存在: ' . $filePath);
            }
        }

        // 反向创建映射：对于每个带尺寸参数的URL，提取其基础URL并映射到新URL
        // 这确保了即使原始URL没有尺寸参数，我们也能处理带尺寸参数的变体
        foreach ($validUrlMap as $originalUrl => $info) {
            $newUrl = $info['new_url'];
            $mediaId = $info['media_id'];

            // 提取文件名和扩展名
            $parsedUrl = parse_url($originalUrl);
            $path = $parsedUrl['path'] ?? '';
            $filename = basename($path);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

            // 构建基础URL（不包含文件名）
            $basePath = dirname($path);
            $baseUrlWithoutFilename = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $basePath . '/';

            // 将基础URL（不包含尺寸参数）映射到新URL
            // 例如：https://example.com/image.png => /uploads/new-image.png
            // 那么 https://example.com/image-1024x526.png 也应该映射到 /uploads/new-image.png
            $baseUrl = $baseUrlWithoutFilename . $baseFilename . '.' . $extension;
            if (!isset($baseUrlToNewUrl[$baseUrl])) {
                $baseUrlToNewUrl[$baseUrl] = [
                    'new_url' => $newUrl,
                    'media_id' => $mediaId,
                ];
            }
        }

        // 合并所有URL映射，包括原始URL和带尺寸参数的变体
        $allUrlMap = array_merge($validUrlMap, $baseUrlToNewUrl);

        // 先处理所有带尺寸参数的URL变体，只执行一次
        if ($contentType === 'html') {
            $content = $this->replaceSizeVariantUrlsInHtml($content, $baseUrlToNewUrl);
        } else {
            $content = $this->replaceSizeVariantUrlsInMarkdown($content, $baseUrlToNewUrl);
        }

        // 首先，遍历所有URL映射，收集被引用的媒体ID
        foreach ($allUrlMap as $originalUrl => $info) {
            $mediaId = $info['media_id'];

            // 在替换URL之前，检查原始URL是否存在于内容中
            if (strpos($contentCopy, $originalUrl) !== false && !in_array($mediaId, $referencedMediaIds)) {
                $referencedMediaIds[] = $mediaId;
                Log::info('添加被引用媒体ID: ' . $mediaId . ' (URL: ' . $originalUrl . ')');
            }
        }

        // 遍历内容中的所有URL，检查是否有带尺寸参数的变体URL
        // 如果有，先尝试使用基础URL，如果基础URL不存在再回退到原始URL
        $this->processUrlVariants($contentCopy, $contentType, $baseUrlToNewUrl, $validUrlMap, $referencedMediaIds);

        // 然后，遍历所有URL映射，替换内容中的URL
        foreach ($allUrlMap as $originalUrl => $info) {
            $newUrl = $info['new_url'];
            $mediaId = $info['media_id'];

            // 根据内容类型进行不同的替换策略
            if ($contentType === 'html') {
                // 改进HTML内容替换，支持更多属性和更灵活的匹配
                $patterns = [
                    // 替换img标签的src属性，支持单引号和双引号
                    '/(<img[^>]*?src=["\'])([^"\']*?' . preg_quote($originalUrl, '/') . '[^"\']*?)(["\'][^>]*?>)/i',
                    // 替换a标签的href属性，支持单引号和双引号
                    '/(<a[^>]*?href=["\'])([^"\']*?' . preg_quote($originalUrl, '/') . '[^"\']*?)(["\'][^>]*?>)/i',
                    // 替换其他可能的属性，如srcset
                    '/(srcset=["\'])([^"\']*?' . preg_quote($originalUrl, '/') . '[^"\']*?)(["\'])/i',
                    // 替换style属性中的URL
                    '/(style=["\'])([^"\']*?url\(["\']?)([^"\']*?' . preg_quote($originalUrl, '/') . '[^"\']*?)(["\']?\)[^"\']*?)(["\'])/i',
                ];

                foreach ($patterns as $pattern) {
                    // 使用preg_replace_callback进行大小写不敏感的替换
                    $content = preg_replace_callback($pattern, function ($matches) use ($originalUrl, $newUrl) {
                        $before = $matches[1];
                        $url = $matches[2];
                        $after = $matches[3];

                        // 大小写不敏感替换URL
                        $replacedUrl = preg_replace('/' . preg_quote($originalUrl, '/') . '/i', $newUrl, $url);

                        return $before . $replacedUrl . $after;
                    }, $content);
                }
            } else {
                // 改进Markdown内容替换，支持更多链接格式
                $patterns = [
                    // 替换Markdown图片链接，支持标题和引用
                    '/!\[([^\]]*)\]\(([^)\s]*?' . preg_quote($originalUrl, '/') . '[^)\s]*?)(?:\s+"[^"]*")?\)/i',
                    // 替换Markdown普通链接，支持标题和引用
                    '/\[([^\]]*)\]\(([^)\s]*?' . preg_quote($originalUrl, '/') . '[^)\s]*?)(?:\s+"[^"]*")?\)/i',
                    // 替换直接URL，支持URL前后的标点符号
                    '/([^\w]|^)(' . preg_quote($originalUrl, '/') . ')([^\w]|$)/i',
                ];

                foreach ($patterns as $pattern) {
                    $content = preg_replace_callback($pattern, function ($matches) use ($originalUrl, $newUrl, $pattern) {
                        if (str_contains($pattern, '!\[')) {
                            // 图片链接替换
                            $altText = $matches[1];
                            $url = $matches[2];
                            // 大小写不敏感替换URL
                            $replacedUrl = preg_replace('/' . preg_quote($originalUrl, '/') . '/i', $newUrl, $url);

                            return '![' . $altText . '](' . $replacedUrl . ')';
                        } elseif (str_contains($pattern, '\[')) {
                            // 普通链接替换
                            $linkText = $matches[1];
                            $url = $matches[2];
                            // 大小写不敏感替换URL
                            $replacedUrl = preg_replace('/' . preg_quote($originalUrl, '/') . '/i', $newUrl, $url);

                            return '[' . $linkText . '](' . $replacedUrl . ')';
                        } else {
                            // 直接URL替换
                            $before = $matches[1];
                            $url = $matches[2];
                            $after = $matches[3];
                            // 大小写不敏感替换URL
                            $replacedUrl = preg_replace('/' . preg_quote($originalUrl, '/') . '/i', $newUrl, $url);

                            return $before . $replacedUrl . $after;
                        }
                    }, $content);
                }
            }
        }

        // 最后，进行全局URL替换，确保所有可能的URL都被替换，使用合并后的URL映射
        $content = $this->globalUrlReplace($content, $allUrlMap, $contentType);

        // 验证替换后的URL有效性
        $this->validateReplacedUrls($content, $contentType);

        return [$content, $referencedMediaIds];
    }

    /**
     * 格式化markdown内容中的图片链接，确保图片单独占一行
     *
     * @param string $markdown markdown内容
     *
     * @return string 格式化后的markdown内容
     */
    protected function formatMarkdownImages(string $markdown): string
    {
        // 1. 匹配连续的图片链接，确保它们之间有换行符
        $pattern1 = '/(!\[[^\]]*\]\([^\)]+\))\s*(?=!\[[^\]]*\]\()/';
        $formattedMarkdown = preg_replace($pattern1, "$1\n\n", $markdown);

        // 2. 匹配图片链接前面有文本的情况，在图片前面添加换行符
        $pattern2 = '/([^\n])(!\[[^\]]*\]\([^\)]+\))/';
        $formattedMarkdown = preg_replace($pattern2, "$1\n$2", $formattedMarkdown);

        // 3. 匹配图片链接后面有文本的情况，在图片后面添加换行符
        $pattern3 = '/(!\[[^\]]*\]\([^\)]+\))([^\n])/';
        $formattedMarkdown = preg_replace($pattern3, "$1\n$2", $formattedMarkdown);

        // 4. 确保连续的换行符不会超过两个
        $pattern4 = '/\n{3,}/';
        $formattedMarkdown = preg_replace($pattern4, "\n\n", $formattedMarkdown);

        // 5. 确保文件开头和结尾没有多余的换行符
        $formattedMarkdown = trim($formattedMarkdown, "\n");

        return $formattedMarkdown;
    }

    /**
     * 验证替换后的URL是否有效
     *
     * @param string $content     替换后的内容
     * @param string $contentType 内容类型（html/markdown）
     *
     * @return void
     */
    protected function validateReplacedUrls(string $content, string $contentType): void
    {
        Log::debug('开始验证替换后的URL，内容类型: ' . $contentType);

        // 提取所有URL
        $urls = [];

        if ($contentType === 'html') {
            // 提取HTML中的所有URL
            preg_match_all('/(?:src|href|srcset|data-src|data-href)=["\']([^"\']*)["\']/', $content, $matches);
            if (!empty($matches[1])) {
                $urls = array_merge($urls, $matches[1]);
            }
        } else {
            // 提取Markdown中的所有URL
            preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)\)|\[([^\]]*)\]\(([^)\s]+)\)/i', $content, $matches);
            if (!empty($matches[2])) {
                $urls = array_merge($urls, $matches[2]);
            }
            if (!empty($matches[4])) {
                $urls = array_merge($urls, $matches[4]);
            }
            // 提取直接URL
            preg_match_all('/https?:\/\/[^\s\)]+|\/[^\s\)]+/i', $content, $matches);
            if (!empty($matches[0])) {
                $urls = array_merge($urls, $matches[0]);
            }
        }

        // 去重并过滤空URL
        $urls = array_unique(array_filter($urls));

        // 验证每个URL
        $validCount = 0;
        $invalidCount = 0;
        $invalidUrls = [];

        foreach ($urls as $url) {
            // 跳过外部URL
            if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
                $validCount++;
                continue;
            }

            // 验证内部URL
            $filePath = public_path($url);
            if (file_exists($filePath)) {
                $validCount++;
            } else {
                $invalidCount++;
                $invalidUrls[] = $url;
                Log::warning('URL无效: ' . $url . ', 文件不存在: ' . $filePath);
            }
        }

        Log::info('URL验证完成，有效: ' . $validCount . ', 无效: ' . $invalidCount . ', 总URL数: ' . count($urls));
        if ($invalidCount > 0) {
            Log::warning('无效URL列表: ' . implode(', ', $invalidUrls));
        }
    }

    /**
     * 全局URL替换，确保所有可能的URL都被替换
     *
     * @param string $content     原始内容
     * @param array  $urlMap      URL映射表
     * @param string $contentType 内容类型
     *
     * @return string 替换后的内容
     */
    protected function globalUrlReplace(string $content, array $urlMap, string $contentType): string
    {
        // 按URL长度降序排序，优先替换长URL，避免短URL匹配到长URL的一部分
        uksort($urlMap, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        // 全局替换所有剩余的URL
        foreach ($urlMap as $originalUrl => $info) {
            $newUrl = $info['new_url'];

            // 避免重复替换
            if (strpos($content, $originalUrl) === false) {
                continue;
            }

            // 根据内容类型使用不同的替换策略
            if ($contentType === 'html') {
                // HTML内容中，只替换属性值中的URL
                $content = preg_replace_callback('/(<[^>]+?)(\w+)=["\']([^"\']*)["\']/', function ($matches) use ($originalUrl, $newUrl) {
                    $tag = $matches[1];
                    $attr = $matches[2];
                    $value = $matches[3];

                    // 只替换常见的URL属性
                    $urlAttributes = ['src', 'href', 'srcset', 'action', 'data-src', 'data-href'];
                    if (in_array($attr, $urlAttributes)) {
                        $value = str_replace($originalUrl, $newUrl, $value);
                    }

                    return $tag . $attr . '="' . $value . '"';
                }, $content);
            } else {
                // Markdown内容中，替换所有出现的URL
                $content = str_replace($originalUrl, $newUrl, $content);
            }
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
        // 匹配带尺寸参数的URL模式，支持更多变体
        $sizePattern = '/(-\d+x\d+)(\.\w+)/i';

        // 提取内容中所有可能的URL，支持更多属性
        preg_match_all('/(src|href|srcset|data-src|data-href)=["\']([^"\']*)["\']/', $content, $matches, PREG_SET_ORDER);

        // 处理每个匹配到的URL
        foreach ($matches as $match) {
            $attr = $match[1];
            $url = $match[2];
            if (empty($url)) {
                continue;
            }

            // 检查URL是否带有尺寸参数
            if (preg_match($sizePattern, $url)) {
                // 获取基础URL
                $baseUrl = $this->getBaseAttachmentUrl($url);
                if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                    $newUrl = $baseUrlToNewUrl[$baseUrl]['new_url'];

                    // 构建替换模式，支持更灵活的匹配
                    $pattern = '/(' . $attr . '=["\'])([^"\']*?' . preg_quote($url, '/') . '[^"\']*?)(["\'])/i';
                    $replacement = '${1}' . $newUrl . '${3}';

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
        // 匹配图片链接 ![alt](url)，支持标题和引用
        preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/i', $content, $imageMatches, PREG_SET_ORDER);
        // 匹配普通链接 [text](url)，支持标题和引用
        preg_match_all('/\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/i', $content, $linkMatches, PREG_SET_ORDER);
        // 匹配直接URL，支持更多图片格式
        preg_match_all('/https?:\/\/[^\s\)]+\.(?:jpe?g|png|gif|bmp|webp|svg|ico|tiff|tif|jpg|jpeg|JPG|PNG|GIF|WEBP)(-\d+x\d+)?/i', $content, $directUrlMatches, PREG_SET_ORDER);

        // 计算直接URL数量时要检查数组是否为空
        $directUrlCount = !empty($directUrlMatches) ? count($directUrlMatches) : 0;
        Log::debug('图片链接数量: ' . count($imageMatches) . ', 普通链接数量: ' . count($linkMatches) . ', 直接URL数量: ' . $directUrlCount);

        // 处理所有类型的URL，包括带尺寸参数的变体
        $allUrls = array_merge($imageMatches, $linkMatches);

        // 处理图片链接和普通链接
        foreach ($allUrls as $match) {
            $fullMatch = $match[0];
            $url = $match[2];
            $isImage = str_starts_with($fullMatch, '!');

            Log::debug('检查' . ($isImage ? '图片' : '普通') . '链接URL: ' . $url);

            // 检查URL是否带有尺寸参数
            $hasSizeParam = preg_match($sizePattern, $url);
            Log::debug('URL是否带有尺寸参数: ' . ($hasSizeParam ? '是' : '否'));

            // 无论URL是否带有尺寸参数，都尝试获取基础URL并替换
            $baseUrl = $this->getBaseAttachmentUrl($url);
            Log::debug('提取的基础URL: ' . ($baseUrl ?: 'null'));

            // 如果获取到基础URL，检查是否在映射表中
            if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                $newUrl = $baseUrlToNewUrl[$baseUrl]['new_url'];
                Log::debug('基础URL在映射表中，替换为: ' . $newUrl);

                // 构建替换后的内容，保留原有的标题和引用
                $title = '';
                if (preg_match('/\(([^)]+)\s+"([^"]+)"\)/', $fullMatch, $titleMatch)) {
                    $title = ' "' . $titleMatch[2] . '"';
                }

                if ($isImage) {
                    $altText = $match[1];
                    $replacement = '![' . $altText . '](' . $newUrl . $title . ')';
                } else {
                    $linkText = $match[1];
                    $replacement = '[' . $linkText . '](' . $newUrl . $title . ')';
                }

                // 执行替换
                $content = str_replace($fullMatch, $replacement, $content);
                Log::debug('替换' . ($isImage ? '图片' : '普通') . '链接: ' . $url . ' => ' . $newUrl);
            } else {
                // 如果基础URL不在映射表中，尝试直接匹配原始URL
                if (isset($baseUrlToNewUrl[$url])) {
                    $newUrl = $baseUrlToNewUrl[$url]['new_url'];
                    Log::debug('直接URL在映射表中，替换为: ' . $newUrl);

                    // 构建替换后的内容，保留原有的标题和引用
                    $title = '';
                    if (preg_match('/\(([^)]+)\s+"([^"]+)"\)/', $fullMatch, $titleMatch)) {
                        $title = ' "' . $titleMatch[2] . '"';
                    }

                    if ($isImage) {
                        $altText = $match[1];
                        $replacement = '![' . $altText . '](' . $newUrl . $title . ')';
                    } else {
                        $linkText = $match[1];
                        $replacement = '[' . $linkText . '](' . $newUrl . $title . ')';
                    }

                    // 执行替换
                    $content = str_replace($fullMatch, $replacement, $content);
                    Log::debug('直接替换' . ($isImage ? '图片' : '普通') . '链接: ' . $url . ' => ' . $newUrl);
                }
            }
        }

        // 处理独立的URL（不包含在Markdown语法中的直接URL）
        foreach ($directUrlMatches as $directUrlMatch) {
            // 确保匹配结果数组不为空
            if (!empty($directUrlMatch)) {
                $matchedUrl = $directUrlMatch[0];

                // 获取基础URL
                $baseUrl = $this->getBaseAttachmentUrl($matchedUrl);

                // 尝试使用基础URL替换
                if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                    $newUrl = $baseUrlToNewUrl[$baseUrl]['new_url'];
                    // 执行替换
                    $content = str_replace($matchedUrl, $newUrl, $content);
                } else {
                    // 尝试直接匹配原始URL
                    if (isset($baseUrlToNewUrl[$matchedUrl])) {
                        $newUrl = $baseUrlToNewUrl[$matchedUrl]['new_url'];
                        // 执行替换
                        $content = str_replace($matchedUrl, $newUrl, $content);
                    }
                }
            }
        }

        // 最后，使用正则表达式全局替换所有带尺寸参数的URL
        // 例如：https://example.com/image-1024x526.png => /uploads/new-image.png
        foreach ($baseUrlToNewUrl as $baseUrl => $info) {
            $newUrl = $info['new_url'];
            // 构建带尺寸参数的URL模式
            $parsedBaseUrl = parse_url($baseUrl);
            $basePath = $parsedBaseUrl['path'] ?? '';
            $baseFilename = pathinfo($basePath, PATHINFO_FILENAME);
            $extension = pathinfo($basePath, PATHINFO_EXTENSION);
            $baseUrlWithoutFilename = $parsedBaseUrl['scheme'] . '://' . $parsedBaseUrl['host'] . dirname($basePath) . '/';

            // 构建正则表达式，匹配所有带尺寸参数的URL变体
            $pattern = '/(' . preg_quote($baseUrlWithoutFilename, '/') . $baseFilename . ')(-\d+x\d+)?(\.' . $extension . ')/i';

            // 全局替换所有匹配的URL
            $content = preg_replace($pattern, $newUrl, $content);
        }

        return $content;
    }

    /**
     * 处理内容中的URL变体，确保先尝试使用基础URL，如果基础URL不存在再回退到原始URL
     *
     * @param string $contentCopy        原始内容副本
     * @param string $contentType        内容类型（html/markdown）
     * @param array  $baseUrlToNewUrl    基础URL到新URL的映射
     * @param array  $validUrlMap        有效URL映射
     * @param array  $referencedMediaIds 被引用的媒体ID列表
     *
     * @return void
     */
    protected function processUrlVariants(string $contentCopy, string $contentType, array $baseUrlToNewUrl, array $validUrlMap, array &$referencedMediaIds): void
    {
        // 提取内容中的所有URL
        $urls = [];

        if ($contentType === 'html') {
            // 提取HTML中的所有URL
            preg_match_all('/(?:src|href|srcset|data-src|data-href)=["\']([^"\']*)["\']/', $contentCopy, $matches);
            if (!empty($matches[1])) {
                $urls = array_merge($urls, $matches[1]);
            }
        } else {
            // 提取Markdown中的所有URL
            preg_match_all('/!\[([^\]]*)\]\(([^\)]+)\)|\[([^\]]*)\]\(([^\)]+)\)/i', $contentCopy, $matches);
            if (!empty($matches[2])) {
                $urls = array_merge($urls, $matches[2]);
            }
            if (!empty($matches[4])) {
                $urls = array_merge($urls, $matches[4]);
            }
            // 提取直接URL
            preg_match_all('/https?:\/\/[^\s\)]+/i', $contentCopy, $matches);
            if (!empty($matches[0])) {
                $urls = array_merge($urls, $matches[0]);
            }
        }

        // 去重并过滤空URL
        $urls = array_unique(array_filter($urls));

        // 处理每个URL
        foreach ($urls as $url) {
            // 检查URL是否带有尺寸参数
            $baseUrl = $this->getBaseAttachmentUrl($url);
            if ($baseUrl && $baseUrl !== $url) {
                // 这个URL带有尺寸参数

                // 先检查基础URL是否存在于映射表中
                if (isset($baseUrlToNewUrl[$baseUrl])) {
                    // 基础URL存在，使用基础URL的映射关系
                    $mediaId = $baseUrlToNewUrl[$baseUrl]['media_id'];
                    if (!in_array($mediaId, $referencedMediaIds)) {
                        $referencedMediaIds[] = $mediaId;
                        Log::info('添加被引用媒体ID: ' . $mediaId . ' (URL: ' . $baseUrl . ', 变体URL: ' . $url . ')');
                    }
                } else {
                    // 基础URL不存在，检查原始URL是否存在于映射表中
                    if (isset($validUrlMap[$url])) {
                        $mediaId = $validUrlMap[$url]['media_id'];
                        if (!in_array($mediaId, $referencedMediaIds)) {
                            $referencedMediaIds[] = $mediaId;
                            Log::info('添加被引用媒体ID: ' . $mediaId . ' (原始URL: ' . $url . ')');
                        }
                    }
                }
            }
        }
    }

    /**
     * 获取 WordPress 附件的原图 URL（仅当检测到尺寸参数时）
     *
     * @param string $url 原始 URL
     *
     * @return string|null 若是 WP 尺寸图则返回原图 URL，否则返回 null
     */
    protected function getBaseAttachmentUrl(string $url): ?string
    {
        // WordPress 标准尺寸后缀：-1024x768 紧贴在扩展名前
        // 使用正向预查，确保只匹配扩展名前的位置
        $sizePattern = '/-(\d+)x(\d+)(?=\.\w+(?:[?#]|$))/i';

        // 快速判断：如果整个 URL 中都不存在尺寸标记，直接返回 null
        if (!preg_match($sizePattern, $url)) {
            return null;
        }

        // 解析 URL（兼容完整 URL / 相对路径）
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null) {
            return null;
        }

        // 再次确认：尺寸参数必须出现在 path 中
        if (!preg_match($sizePattern, $path)) {
            return null;
        }

        // 移除尺寸参数
        $basePath = preg_replace($sizePattern, '', $path);

        // 还原 query / fragment
        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        $queryStr = $query ? '?' . $query : '';
        $fragmentStr = $fragment ? '#' . $fragment : '';

        // 如果是完整 URL，重建完整结构
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if ($scheme && $host) {
            $portStr = $port ? ':' . $port : '';

            return "{$scheme}://{$host}{$portStr}{$basePath}{$queryStr}{$fragmentStr}";
        }

        // 相对路径
        return $basePath . $queryStr . $fragmentStr;
    }

    /**
     * 第三阶段：处理所有评论
     *
     * @param string $xmlFile    XML文件路径
     * @param int    $totalItems 总项目数
     *
     * @return void
     * @throws Exception
     */
    protected function processAllComments(string $xmlFile, int $totalItems): void
    {
        Log::info('开始处理所有评论');

        $reader = new XMLReader();
        if (!$reader->open($xmlFile)) {
            throw new Exception('无法打开XML文件: ' . $xmlFile);
        }

        $processedItems = 0;
        $commentCount = 0;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                $processedItems++;

                try {
                    $doc = new DOMDocument();
                    $libxmlErrors = libxml_use_internal_errors(true);
                    $node = $doc->importNode($reader->expand(), true);
                    $doc->appendChild($node);

                    $xpath = new DOMXPath($doc);
                    $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');

                    // 恢复错误报告设置
                    libxml_use_internal_errors($libxmlErrors);

                    $postType = $xpath->evaluate('string(wp:post_type)', $node);

                    // 只处理文章和页面的评论
                    if ($postType === 'post' || $postType === 'page') {
                        $wpPostId = $xpath->evaluate('string(wp:post_id)', $node);
                        $commentsProcessed = $this->processComments($xpath, $node, $wpPostId);
                        $commentCount += $commentsProcessed;
                    }
                } catch (Exception $e) {
                    Log::error('处理评论时出错: ' . $e->getMessage(), ['exception' => $e]);
                }

                // 更新进度（第三阶段區20%进度，从80%到100%）
                $progress = 80 + intval(($processedItems / max(1, $totalItems)) * 20);
                $this->importJob->update([
                    'progress' => $progress,
                    'message' => "第三阶段：处理评论 ({$processedItems}/{$totalItems})",
                ]);
            }
        }

        $reader->close();
        Log::info('评论处理完成，共处理评论: ' . $commentCount . ' 条');
    }

    /**
     * 处理单篇文章的评论
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     * @param string   $wpPostId WordPress文章ID
     *
     * @return int 处理的评论数量
     */
    protected function processComments(DOMXPath $xpath, DOMNode $node, string $wpPostId): int
    {
        // 检查是否存在文章ID映射
        if (empty($wpPostId) || !isset($this->postIdMap[$wpPostId])) {
            Log::debug('文章ID不存在或未映射，跳过评论处理: WP ID ' . $wpPostId);

            return 0;
        }

        $newPostId = $this->postIdMap[$wpPostId];
        Log::debug('处理文章评论，WP文章ID: ' . $wpPostId . ', 新文章ID: ' . $newPostId);

        // 获取所有评论节点
        $commentNodes = $xpath->query('wp:comment', $node);
        if (!$commentNodes || $commentNodes->length === 0) {
            Log::debug('文章无评论');

            return 0;
        }

        $commentCount = 0;

        // 第一遍：导入所有评论（不处理父评论关系）
        foreach ($commentNodes as $commentNode) {
            try {
                $wpCommentId = $xpath->evaluate('string(wp:comment_id)', $commentNode);
                $wpCommentParentId = $xpath->evaluate('string(wp:comment_parent)', $commentNode);
                $commentAuthor = $xpath->evaluate('string(wp:comment_author)', $commentNode);
                $commentAuthorEmail = $xpath->evaluate('string(wp:comment_author_email)', $commentNode);
                $commentContent = $xpath->evaluate('string(wp:comment_content)', $commentNode);
                $commentDate = $xpath->evaluate('string(wp:comment_date)', $commentNode);
                $commentApproved = $xpath->evaluate('string(wp:comment_approved)', $commentNode);
                $commentAuthorIP = $xpath->evaluate('string(wp:comment_author_IP)', $commentNode);
                $commentUserAgent = $xpath->evaluate('string(wp:comment_agent)', $commentNode);

                // 转换为UTF-8编码
                $commentAuthor = $this->convertToUtf8($commentAuthor);
                $commentAuthorEmail = $this->convertToUtf8($commentAuthorEmail);
                $commentContent = $this->convertToUtf8($commentContent);

                // 转换评论状态
                $status = 'pending';
                if ($commentApproved === '1') {
                    $status = 'approved';
                } elseif ($commentApproved === 'spam') {
                    $status = 'spam';
                } elseif ($commentApproved === 'trash') {
                    $status = 'rejected';
                }

                // 创建评论记录
                $comment = new Comment();
                $comment->post_id = $newPostId;
                $comment->user_id = null; // WordPress导出的评论通常是访客评论
                $comment->parent_id = null; // 稍后在第二遍处理中更新
                $comment->guest_name = $commentAuthor ?: '匿名用户';
                $comment->guest_email = $commentAuthorEmail ?: '';
                $comment->content = $commentContent;
                $comment->status = $status;
                $comment->ip_address = $commentAuthorIP ?: '';
                $comment->user_agent = $commentUserAgent ?: '';
                $comment->created_at = $commentDate && $commentDate !== '0000-00-00 00:00:00'
                    ? date('Y-m-d H:i:s', strtotime($commentDate))
                    : utc_now_string('Y-m-d H:i:s');
                $comment->updated_at = utc_now_string('Y-m-d H:i:s');

                $comment->save();

                // 保存WordPress评论ID到新评论ID的映射
                if (!empty($wpCommentId)) {
                    $this->commentIdMap[$wpCommentId] = [
                        'new_id' => $comment->id,
                        'wp_parent_id' => $wpCommentParentId,
                    ];
                    Log::debug('保存评论ID映射: WP ID ' . $wpCommentId . ' => 新ID ' . $comment->id);
                }

                $commentCount++;
            } catch (Exception $e) {
                Log::error('创建评论时出错: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        // 第二遍：更新父评论关系
        foreach ($this->commentIdMap as $wpCommentId => $commentData) {
            $wpParentId = $commentData['wp_parent_id'];
            if (!empty($wpParentId) && $wpParentId !== '0' && isset($this->commentIdMap[$wpParentId])) {
                try {
                    $newCommentId = $commentData['new_id'];
                    $newParentId = $this->commentIdMap[$wpParentId]['new_id'];

                    Comment::where('id', $newCommentId)->update([
                        'parent_id' => $newParentId,
                    ]);

                    Log::debug('更新评论父子关系: 评论ID ' . $newCommentId . ' 的父评论ID设为 ' . $newParentId);
                } catch (Exception $e) {
                    Log::error('更新评论父子关系时出错: ' . $e->getMessage(), ['exception' => $e]);
                }
            }
        }

        Log::debug('文章评论处理完成，共处理 ' . $commentCount . ' 条评论');

        return $commentCount;
    }
}
