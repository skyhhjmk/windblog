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
use League\CommonMark\Exception\CommonMarkException;
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
    protected array $options = [];

    /**
     * 默认作者ID
     *
     * @var int
     */
    protected ?int $defaultAuthorId = null;

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

    protected ?MarkdownConverter $excerptConverter = null;

    protected array $htmlToMarkdownConverters = [];

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
        $fixedXmlFile = $this->importJob->file_path;

        try {
            Log::info('开始执行WordPress导入任务: ' . $this->importJob->name);

            $this->importJob->update([
                'progress' => 0,
                'message' => '开始执行导入任务，修复XML文件命名空间',
            ]);

            // 修复XML文件命名空间
            $fixedXmlFile = $this->fixXmlNamespaces($this->importJob->file_path);
            if (!file_exists($fixedXmlFile)) {
                throw new Exception('XML文件修复失败或文件不存在: ' . $fixedXmlFile);
            }

            $this->importJob->update([
                'progress' => 5,
                'message' => 'XML文件修复完成，开始计算项目总数',
            ]);

            // 计算XML项目总数
            $totalItems = $this->countItems($fixedXmlFile);
            if ($totalItems === 0) {
                throw new Exception('XML文件中没有找到任何项目');
            }

            Log::info('XML项目总数: ' . $totalItems);
            $this->importJob->update([
                'progress' => 10,
                'message' => '项目总数计算完成，开始处理XML项目，总数: ' . $totalItems,
            ]);

            // 第一阶段：处理附件
            $this->processAllAttachments($fixedXmlFile, $totalItems);

            // 第二阶段：处理文章和页面
            $this->processAllPosts($fixedXmlFile, $totalItems);

            // 清空附件映射
            $this->attachmentMap = [];
            Log::debug('清空附件映射关系');

            // 第三阶段：处理评论
            if (!empty($this->options['import_comments'])) {
                $this->processAllComments($fixedXmlFile, $totalItems);
            }

            // 最终进度更新
            $this->importJob->update([
                'progress' => 100,
                'message' => '导入任务完成',
            ]);

            Log::info('WordPress XML导入任务完成: ' . $this->importJob->name);

            return true;

        } catch (Throwable $e) {
            Log::error('导入任务执行错误: ' . $e->getMessage(), ['exception' => $e]);
            $this->importJob->update([
                'status' => 'failed',
                'progress' => 0,
                'message' => '导入任务执行错误: ' . $e->getMessage(),
            ]);

            return false;

        } finally {
            // 清理临时文件
            if ($fixedXmlFile !== $this->importJob->file_path && file_exists($fixedXmlFile)) {
                @unlink($fixedXmlFile);
                Log::info('删除临时修复文件: ' . $fixedXmlFile);
            }

            // 清空映射关系
            $this->postIdMap = [];
            $this->commentIdMap = [];
            Log::debug('清空文章和评论映射关系');
        }
    }

    /**
     * 修复XML中的命名空间问题
     *
     * @param string $xmlFile
     *
     * @return string
     */
    protected function fixXmlNamespaces(string $xmlFile): string
    {
        try {
            if (!file_exists($xmlFile) || !is_readable($xmlFile)) {
                Log::warning("XML文件不存在或不可读: $xmlFile");

                return $xmlFile;
            }

            Log::info('读取XML文件内容: ' . $xmlFile);
            $content = file_get_contents($xmlFile);

            $namespaceFixes = [
                'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
                'content' => 'http://purl.org/rss/1.0/modules/content/',
                'wfw' => 'http://wellformedweb.org/CommentAPI/',
                'dc' => 'http://search.yahoo.com/mrss/',
                'wp' => 'http://wordpress.org/export/1.2/',
                'itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd',
            ];

            $rssTagPos = strpos($content, '<rss');
            if ($rssTagPos === false) {
                Log::warning('XML中未找到 <rss> 标签');

                return $xmlFile;
            }

            $rssEndPos = strpos($content, '>', $rssTagPos);
            if ($rssEndPos === false) {
                Log::warning('未找到 <rss> 标签的结束符');

                return $xmlFile;
            }

            $rssTag = substr($content, $rssTagPos, $rssEndPos - $rssTagPos + 1);
            Log::info('原始RSS标签: ' . $rssTag);

            $missingNamespaces = [];
            foreach ($namespaceFixes as $prefix => $uri) {
                if (!preg_match('/\bxmlns:' . preg_quote($prefix, '/') . '\b/i', $rssTag)) {
                    $missingNamespaces[] = "xmlns:$prefix=\"$uri\"";
                }
            }

            if (!empty($missingNamespaces)) {
                $newRssTag = substr($rssTag, 0, -1) . ' ' . implode(' ', $missingNamespaces) . '>';
                Log::info('修复后的RSS标签: ' . $newRssTag);
                $content = substr_replace($content, $newRssTag, $rssTagPos, strlen($rssTag));

                $runtimeDir = runtime_path('imports');
                if (!is_dir($runtimeDir)) {
                    mkdir($runtimeDir, 0o777, true);
                }

                $fixedXmlFile = $runtimeDir . '/fixed_' . basename($xmlFile);
                file_put_contents($fixedXmlFile, $content);
                Log::info('创建修复后的XML文件: ' . $fixedXmlFile);

                return $fixedXmlFile;
            }

            Log::info('XML文件命名空间完整，无需修复');

            return $xmlFile;

        } catch (Throwable $e) {
            Log::warning('修复XML命名空间时出错: ' . $e->getMessage());

            return $xmlFile;
        }
    }

    /**
     * 计算 XML 项目数
     *
     * @param string $xmlFile
     *
     * @return int
     */
    protected function countItems(string $xmlFile): int
    {
        Log::info('计算XML项目数: ' . $xmlFile);

        if (!file_exists($xmlFile) || !is_readable($xmlFile)) {
            Log::warning('XML文件不存在或不可读: ' . $xmlFile);

            return 0;
        }

        $count = 0;
        $reader = new XMLReader();

        libxml_use_internal_errors(true); // 捕获XML解析错误

        try {
            if (!$reader->open($xmlFile)) {
                Log::warning('无法打开XML文件进行计数: ' . $xmlFile);

                return 0;
            }

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'item') {
                    $count++;
                }
            }
        } catch (Throwable $e) {
            Log::error('计算XML项目数出错: ' . $e->getMessage());

            return 0;
        } finally {
            $reader->close();
        }

        Log::info('XML项目数计算完成: ' . $count);

        return $count;
    }

    /**
     * 处理所有附件
     *
     * @param string $xmlFile
     * @param int    $totalItems
     *
     * @return void
     * @throws Exception
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

        libxml_use_internal_errors(true); // 捕获DOM解析错误

        try {
            Log::info('开始收集所有附件信息');

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'item') {
                    $processedItems++;
                    try {
                        $doc = new DOMDocument();
                        $node = $doc->importNode($reader->expand(), true);
                        $doc->appendChild($node);

                        $xpath = new DOMXPath($doc);
                        $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');

                        $postType = $xpath->evaluate('string(wp:post_type)', $node);

                        if ($postType === 'attachment' && !empty($this->options['import_attachments'])) {
                            $title = $xpath->evaluate('string(title)', $node);
                            $url = $xpath->evaluate('string(wp:attachment_url)', $node);
                            if (!empty($url)) {
                                $attachmentsToProcess[] = ['title' => $title, 'url' => $url];
                                $attachmentCount++;
                            }
                        }
                    } catch (Throwable $e) {
                        Log::error('收集附件信息时出错: ' . $e->getMessage(), ['exception' => $e]);
                    }

                    // 更新收集进度 (0~10%)
                    $progress = intval(($processedItems / max(1, $totalItems)) * 10);
                    $this->importJob->update([
                        'progress' => $progress,
                        'message' => "第一阶段：收集附件信息 ({$processedItems}/{$totalItems})",
                    ]);
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors(false);
        }

        Log::info('附件信息收集完成，共收集附件: ' . $attachmentCount);

        if (empty($attachmentsToProcess)) {
            Log::info('没有附件需要处理');
            $this->importJob->update([
                'progress' => 30,
                'message' => '第一阶段：附件处理完成 (0/0)',
            ]);

            return;
        }

        // 下载附件
        Log::info('开始多线程处理附件，共 ' . count($attachmentsToProcess) . ' 个');
        $processedAttachments = 0;
        $batchSize = 10;
        $batches = array_chunk($attachmentsToProcess, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $batchResults = $this->downloadAttachments($batch);

                foreach ($batchResults as $result) {
                    if ($result && !empty($result['id']) && !empty($result['original_url'])) {
                        $this->attachmentMap[$result['original_url']] = $result;
                        Log::debug('保存附件映射: ' . $result['original_url'] . ' => 媒体ID: ' . $result['id']);
                    }
                }
            } catch (Throwable $e) {
                Log::error('批次附件下载失败: ' . $e->getMessage(), ['batchIndex' => $batchIndex, 'exception' => $e]);
            }

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
     * 处理所有文章/页面
     *
     * @param string $xmlFile
     * @param int    $totalItems
     *
     * @return void
     * @throws CommonMarkException
     * @throws Throwable
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

        try {
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'item') {
                    $processedItems++;

                    try {
                        $doc = new DOMDocument();
                        $libxmlErrors = libxml_use_internal_errors(true);
                        try {
                            $node = $doc->importNode($reader->expand(), true);
                            $doc->appendChild($node);

                            $xpath = new DOMXPath($doc);
                            $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
                        } finally {
                            libxml_use_internal_errors($libxmlErrors);
                        }

                        $postType = $xpath->evaluate('string(wp:post_type)', $node);

                        // 只导入文章类型，跳过页面类型
                        if ($postType === 'post') {
                            $this->processPost($xpath, $node, $postType);
                            $postCount++;
                        } elseif ($postType === 'page') {
                            // 跳过页面类型导入
                            $title = $xpath->evaluate('string(title)', $node);
                            Log::info('跳过页面类型导入: ' . $title);
                        }
                    } catch (Exception $e) {
                        Log::error('处理文章/页面项目时出错: ' . $e->getMessage(), ['exception' => $e]);
                    }

                    // 更新进度
                    $progress = 30 + intval(($processedItems / max(1, $totalItems)) * 50);
                    $this->importJob->update([
                        'progress' => $progress,
                        'message' => "第二阶段：处理文章和页面 ({$processedItems}/{$totalItems})",
                    ]);
                }
            }
        } finally {
            $reader->close();
        }

        Log::info('文章和页面处理完成，共处理文章: ' . $postCount . ' 篇，页面: ' . $pageCount . ' 个');
    }

    /**
     * 处理单个文章/页面
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     * @param string   $postType
     *
     * @return void
     * @throws CommonMarkException
     * @throws Throwable
     */
    protected function processPost(DOMXPath $xpath, DOMNode $node, string $postType): void
    {
        /** $postType 未使用，保留参数以兼容未来页面导入 */
        Log::debug('处理文章/页面，postType: ' . $postType);

        // 提取文章数据
        $data = $this->extractPostDataFromXml($xpath, $node);

        // 过滤特殊页面，跳过包含特定关键词的文章
        $specialPageKeywords = ['动态', '标签'];
        foreach ($specialPageKeywords as $keyword) {
            if (str_contains($data['title'], $keyword)) {
                Log::info('跳过特殊页面导入: ' . $data['title']);

                return;
            }
        }

        // 标准化内容并获取 content type
        [$contentType, $data['content'], $data['excerpt']] = $this->normalizeContent(
            $data['content'],
            $data['excerpt']
        );
        $data['content_type'] = $contentType;

        // 替换附件并收集引用
        [$data['content'], $contentMedia] = $this->replaceAttachmentsAndCollectMedia(
            $data['content'],
            $contentType
        );
        [$data['excerpt'], $excerptMedia] = $this->replaceAttachmentsAndCollectMedia(
            $data['excerpt'],
            $contentType
        );
        $referencedMediaIds = array_values(array_unique(array_merge($contentMedia, $excerptMedia)));

        // 处理作者、分类、标签
        $authorId = $this->processAuthor($xpath, $node);
        $categoryIds = $this->processCategories($xpath, $node);
        $tagIds = $this->processTags($xpath, $node);

        // 映射文章状态并写回 $data
        $data['status'] = $this->mapPostStatus($data['status']);

        // 解析文章 slug
        $slug = $this->resolvePostSlug($data);

        // 查重
        $existingPost = Post::where('slug', $slug)->first();
        $duplicateMode = $this->options['duplicate_mode'] ?? 'skip';

        if ($existingPost) {
            $this->handleDuplicatePost(
                $existingPost,
                $duplicateMode,
                $data,
                $contentType,
                $authorId,
                $categoryIds,
                $tagIds,
                $referencedMediaIds
            );

            return;
        }

        // 新建文章
        $post = $this->createNewPost(
            $data,
            $referencedMediaIds,
            $data['wp_id'] ?: null
        );

        // 同步作者、分类、标签关系
        $this->syncPostRelations($post, $authorId, $categoryIds, $tagIds);

        Log::debug('文章保存完成，ID: ' . $post->id);
    }

    /**
     * XML 数据提取
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     *
     * @return array
     */
    protected function extractPostDataFromXml(DOMXPath $xpath, DOMNode $node): array
    {
        return [
            'title' => $this->convertToUtf8($xpath->evaluate('string(title)', $node)),
            'content' => $this->convertToUtf8($xpath->evaluate('string(content:encoded)', $node)),
            'excerpt' => $this->convertToUtf8($xpath->evaluate('string(excerpt:encoded)', $node)),
            'status' => $xpath->evaluate('string(wp:status)', $node),
            'slug' => $xpath->evaluate('string(wp:post_name)', $node),
            'post_date' => $xpath->evaluate('string(wp:post_date)', $node),
            'wp_id' => $xpath->evaluate('string(wp:post_id)', $node),
        ];
    }

    /**
     * 内容规范化
     *
     * @param string $content
     * @param string $excerpt
     *
     * @return array
     */
    protected function normalizeContent(string $content, string $excerpt): array
    {
        $type = $this->options['convert_to'] === 'html' ? 'html' : 'markdown';

        if ($type === 'markdown') {
            $content = $content ? $this->convertHtmlToMarkdown($content) : '';
            $excerpt = $excerpt ? $this->convertHtmlToMarkdown($excerpt) : '';
        }

        return [$type, $content, $excerpt];
    }

    /**
     * 替换附件链接
     *
     * @param string $content
     * @param string $type
     *
     * @return array
     */
    protected function replaceAttachmentsAndCollectMedia(string $content, string $type): array
    {
        if (empty($content) || empty($this->attachmentMap)) {
            return [$content, []];
        }

        [$content, $mediaIds] = $this->replaceAttachmentLinks($content, $type);

        if ($type === 'markdown') {
            $content = $this->formatMarkdownImages($content);
        }

        return [$content, $mediaIds];
    }

    /**
     * 处理文章 slug
     *
     * @param array $data
     *
     * @return string
     */
    protected function resolvePostSlug(array $data): string
    {
        if (!empty($data['slug'])) {
            return $data['slug'];
        }

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return $data['wp_id'] ?: uniqid();
        }

        $unnamed = ['未命名', 'untitled', 'unnamed', '无标题', 'new post'];
        foreach ($unnamed as $p) {
            if (stripos($title, $p) !== false) {
                return $data['wp_id'] ?: uniqid();
            }
        }

        $slug = Str::slug($this->translateTitle($title));

        return $slug ?: ($data['wp_id'] ?: uniqid());
    }

    /**
     * 处理重复文章
     *
     * @param Post     $post
     * @param string   $mode
     * @param array    $data
     * @param string   $contentType
     * @param int|null $authorId
     * @param array    $categoryIds
     * @param array    $tagIds
     * @param array    $mediaIds
     *
     * @return void
     */
    protected function handleDuplicatePost(
        Post $post,
        string $mode,
        array $data,
        string $contentType,
        ?int $authorId,
        array $categoryIds,
        array $tagIds,
        array $mediaIds
    ): void {
        if ($mode !== 'overwrite') {
            if (!empty($data['wp_id'])) {
                $this->postIdMap[$data['wp_id']] = $post->id;
            }

            return;
        }

        $post->update([
            'content_type' => $contentType,
            'content' => $data['content'],
            'excerpt' => $data['excerpt'],
            'status' => $this->mapPostStatus($data['status']),
        ]);

        $this->syncPostRelations($post, $authorId, $categoryIds, $tagIds);
        $this->updateMediaReferences($post->id, $mediaIds);

        if (!empty($data['wp_id'])) {
            $this->postIdMap[$data['wp_id']] = $post->id;
        }
    }

    /**
     * 映射文章状态
     *
     * @param string $wpStatus
     *
     * @return string
     */
    protected function mapPostStatus(string $wpStatus): string
    {
        return match ($wpStatus) {
            'publish' => 'published',
            //            'draft',
            //            'private',
            //            'pending',
            //            'future' => 'draft',
            default => 'draft',
        };
    }

    /**
     * 同步文章关系
     *
     * @param Post     $post
     * @param int|null $authorId
     * @param array    $categoryIds
     * @param array    $tagIds
     *
     * @return void
     */
    protected function syncPostRelations(
        Post $post,
        ?int $authorId,
        array $categoryIds,
        array $tagIds
    ): void {
        // 作者
        if ($authorId) {
            $author = Author::find($authorId);
            if ($author) {
                $post->authors()->sync([
                    $author->id => [
                        'is_primary' => 1,
                        'created_at' => utc_now_string('Y-m-d H:i:s'),
                        'updated_at' => utc_now_string('Y-m-d H:i:s'),
                    ],
                ]);
            }
        } else {
            $post->authors()->detach();
        }

        // 分类
        $post->categories()->sync($categoryIds ?: []);

        // 标签
        $post->tags()->sync($tagIds ?: []);
    }

    /**
     * 创建新文章
     *
     * @param array       $data
     * @param array       $referencedMediaIds
     * @param string|null $wpPostId
     *
     * @return Post
     * @throws CommonMarkException
     * @throws Throwable
     */
    protected function createNewPost(
        array $data,
        array $referencedMediaIds,
        ?string $wpPostId
    ): Post {
        $post = new Post();
        $post->title = $data['title'] ?: '无标题';
        $post->content_type = $data['content_type'];
        $post->content = $data['content'];
        $post->excerpt = $this->generateExcerpt($data['excerpt'] ?: $data['content']);
        $post->status = $data['status'];
        $post->slug = $data['slug'];
        $post->created_at = $data['post_date'];
        $post->updated_at = utc_now_string('Y-m-d H:i:s');

        try {
            $post->save();
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'posts_slug_key')
                || str_contains($e->getMessage(), 'SQLSTATE[23505]')
            ) {
                $existing = Post::where('slug', $post->slug)->first();
                if ($existing) {
                    if ($wpPostId) {
                        $this->postIdMap[$wpPostId] = $existing->id;
                    }
                    $this->updateMediaReferences($existing->id, $referencedMediaIds);

                    return $existing;
                }
            }
            throw $e;
        }

        if ($wpPostId) {
            $this->postIdMap[$wpPostId] = $post->id;
        }

        $this->updateMediaReferences($post->id, $referencedMediaIds);

        return $post;
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

        // 去重 + 过滤非法值
        $referencedMediaIds = array_values(array_unique(array_filter(
            $referencedMediaIds,
            static fn ($id) => is_int($id) || ctype_digit((string) $id)
        )));

        if (empty($referencedMediaIds)) {
            return;
        }

        Log::debug(
            '更新媒体引用计数',
            ['post_id' => $postId, 'media_ids' => $referencedMediaIds]
        );

        foreach ($referencedMediaIds as $mediaId) {
            try {
                /** @var Media|null $media */
                $media = Media::find((int) $mediaId);
                if (!$media) {
                    Log::warning('媒体ID不存在，跳过引用更新', [
                        'media_id' => $mediaId,
                        'post_id' => $postId,
                    ]);
                    continue;
                }

                /**
                 * addPostReference 内部应保证幂等
                 * （同一 postId 不应重复增加）
                 */
                $media->addPostReference($postId);
                $media->save();

                Log::debug('媒体引用计数更新成功', [
                    'media_id' => $mediaId,
                    'post_id' => $postId,
                ]);
            } catch (Throwable $e) {
                Log::error('更新媒体引用计数失败', [
                    'media_id' => $mediaId,
                    'post_id' => $postId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 翻译标题并生成 slug（使用 SlugTranslateService）
     *
     * @param string $title
     *
     * @return string
     */
    protected function translateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return 'post-' . uniqid();
        }

        try {
            // 使用 SlugTranslateService
            $service = new SlugTranslateService();

            // 从导入配置中获取翻译模式和 AI 选择
            $mode = $this->options['slug_translate_mode']
                ?? blog_config('slug_translate_mode', 'auto', true);

            $aiSelection = $this->options['slug_translate_ai_selection']
                ?? blog_config('slug_translate_ai_selection', '', true);

            $result = $service->translate($title, [
                'mode' => $mode,
                'ai_selection' => $aiSelection ?: null,
            ]);

            /**
             * 翻译结果必须是“非空字符串”才算成功
             */
            if (is_string($result) && trim($result) !== '') {
                return $this->formatTitleAsSlug($result);
            }
        } catch (Throwable $e) {
            Log::warning('翻译标题时出错: ' . $e->getMessage(), [
                'title' => $title,
            ]);
        }

        // 兜底：使用原标题生成 slug
        return $this->formatTitleAsSlug($title);
    }

    /**
     * 将标题格式化为 slug（URL 友好）
     *
     * @param string $title
     *
     * @return string
     */
    protected function formatTitleAsSlug(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return 'post-' . uniqid();
        }

        /**
         * 驼峰 → 空格（必须在 strtolower 之前）
         * 例如：HelloWorld → Hello World
         */
        $title = preg_replace('/([a-z])([A-Z])/', '$1 $2', $title);

        $title = strtolower($title);

        /**
         * 非字母数字 / 中文 → 连字符
         * 保留：a-z 0-9 中文
         */
        $title = preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $title);

        // 清理多余连字符
        $title = trim($title, '-');
        $title = preg_replace('/-+/', '-', $title);

        if ($title === '') {
            return 'post-' . uniqid();
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
        $authorName = trim((string) $xpath->evaluate('string(dc:creator)', $node));
        Log::debug('处理作者: ' . ($authorName ?: '[空]'));

        /**
         * XML 中未提供作者 → 使用默认作者
         */
        if ($authorName === '') {
            Log::debug('使用默认作者ID（XML无作者）: ' . var_export($this->defaultAuthorId, true));

            return $this->defaultAuthorId;
        }

        /**
         * 按 username 精确查找
         */
        try {
            $author = Author::where('username', $authorName)->first();
            if ($author) {
                Log::debug('找到现有作者: ' . $author->username . ' (ID: ' . $author->id . ')');

                return (int) $author->id;
            }
        } catch (Throwable $e) {
            Log::warning(
                '作者查找异常，回退默认作者',
                ['authorName' => $authorName, 'exception' => $e]
            );
        }

        /**
         * 查找失败 → 回退默认作者
         */
        Log::debug(
            '未找到作者，回退默认作者ID: ' . var_export($this->defaultAuthorId, true)
        );

        return $this->defaultAuthorId;
    }

    /**
     * 收集分类IDs（支持多个）
     *
     * 优先使用 nicename 作为 slug；
     * 无 nicename 时，使用翻译或 slugify 生成 slug。
     * 若已存在则复用；否则创建后返回ID。
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     *
     * @return int[] 分类ID数组
     * @throws Throwable
     */
    protected function processCategories(DOMXPath $xpath, DOMNode $node): array
    {
        $ids = [];

        $nodes = $xpath->query('category[@domain="category"]', $node);
        if (!$nodes || $nodes->length === 0) {
            Log::debug('无分类信息');

            return [];
        }

        foreach ($nodes as $n) {
            $name = trim((string) $n->nodeValue);
            if ($name === '') {
                continue;
            }

            $nicename = '';
            if ($n instanceof DOMElement) {
                $nicename = trim((string) $n->getAttribute('nicename'));
            }

            /**
             * 1️⃣ 生成 slug
             * nicename → translate → Str::slug → random
             */
            $slug = $nicename;
            if ($slug === '') {
                $slug = (string) $this->translateTitle($name);
            }
            if ($slug === '') {
                $slug = Str::slug($name);
            }
            if ($slug === '') {
                $slug = 'category-' . Str::random(8);
            }

            /**
             * 2️⃣ 优先通过 slug 查找，其次通过 name 查找
             */
            $category = Category::where('slug', $slug)->first();
            if (!$category) {
                $category = Category::where('name', $name)->first();
            }

            if ($category) {
                $ids[] = (int) $category->id;
                Log::debug("复用分类: {$category->name} ({$category->id})");
                continue;
            }

            /**
             * 3️⃣ slug 唯一化
             */
            $baseSlug = $slug;
            $suffix = 1;
            while (Category::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }

            try {
                $category = new Category();
                $category->name = $name;
                $category->slug = $slug;
                $category->parent_id = null;     // WP 层级此处暂不解析
                $category->sort_order = 0;
                $category->created_at = utc_now_string('Y-m-d H:i:s');
                $category->updated_at = utc_now_string('Y-m-d H:i:s');
                $category->save();

                $ids[] = (int) $category->id;
                Log::debug('新分类创建完成，ID: ' . $category->id);
            } catch (Throwable $e) {
                Log::error(
                    '创建分类时出错',
                    ['name' => $name, 'slug' => $slug, 'exception' => $e]
                );
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 收集标签IDs（支持多个）
     *
     * 优先使用 nicename 作为 slug；
     * 无 nicename 时，使用翻译或 slugify 生成 slug。
     * 若已存在则复用；否则创建后返回ID。
     *
     * @param DOMXPath $xpath
     * @param DOMNode  $node
     *
     * @return int[] 标签ID数组
     * @throws Throwable
     */
    protected function processTags(DOMXPath $xpath, DOMNode $node): array
    {
        $ids = [];

        $nodes = $xpath->query('category[@domain="post_tag"]', $node);
        if (!$nodes || $nodes->length === 0) {
            Log::debug('无标签信息');

            return [];
        }

        foreach ($nodes as $n) {
            $name = trim((string) $n->nodeValue);
            if ($name === '') {
                continue;
            }

            $nicename = '';
            if ($n instanceof DOMElement) {
                $nicename = trim((string) $n->getAttribute('nicename'));
            }

            /**
             * 生成 slug
             * 优先 nicename → translate → Str::slug
             */
            $slug = $nicename;
            if ($slug === '') {
                $slug = (string) $this->translateTitle($name);
            }
            if ($slug === '') {
                $slug = Str::slug($name);
            }

            // 极端兜底（避免 time() 冲突）
            if ($slug === '') {
                $slug = 'tag-' . Str::random(8);
            }

            /**
             * 优先通过 slug 查找，其次通过 name 查找
             */
            $tag = Tag::where('slug', $slug)->first();
            if (!$tag) {
                $tag = Tag::where('name', $name)->first();
            }

            if ($tag) {
                $ids[] = (int) $tag->id;
                Log::debug("复用标签: {$tag->name} ({$tag->id})");
                continue;
            }

            /**
             * slug 唯一化（防止并发或历史数据冲突）
             */
            $baseSlug = $slug;
            $suffix = 1;
            while (Tag::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $suffix;
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
            } catch (Throwable $e) {
                Log::error(
                    '创建标签时出错',
                    ['name' => $name, 'slug' => $slug, 'exception' => $e]
                );
            }
        }

        // 去重 + 重建索引
        return array_values(array_unique($ids));
    }

    /**
     * 解析默认作者ID：
     * 1) 任务中已指定 author_id 则使用（构造函数中已处理）
     * 2) 否则查找最早创建的管理员，并使用与其 username 相同的普通用户
     * 3) 否则使用任意一个已有普通用户（id最小）
     * 4) 都没有则返回 null
     */
    protected function resolveDefaultAuthorId(): ?int
    {
        try {
            // 2) 查找最早创建的管理员
            $admin = Admin::orderBy('id', 'asc')->first();
            if ($admin && !empty($admin->username)) {
                // 使用与管理员 username 相同的普通用户作为默认作者
                $user = Author::where('username', $admin->username)->first();
                if ($user) {
                    return (int) $user->id;
                }

                Log::warning(
                    '未找到与管理员 username 对应的作者用户',
                    ['admin_username' => $admin->username]
                );
            }

            // 3) 回退：选取任意一个已有普通用户
            $any = Author::orderBy('id', 'asc')->first();
            if ($any) {
                return (int) $any->id;
            }
        } catch (Throwable $e) {
            Log::warning(
                '解析默认作者ID失败',
                ['exception' => $e]
            );
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
        $title = trim($xpath->evaluate('string(wp:post_title)', $node));
        $url = $xpath->evaluate('string(wp:attachment_url)', $node);

        if (empty($url)) {
            Log::debug('附件URL为空，跳过');

            return null;
        }

        if ($title === '') {
            $path = parse_url($url, PHP_URL_PATH);
            $title = $path ? basename($path) : 'attachment';
        }

        Log::debug('处理附件 - 标题: ' . $title . ', URL: ' . $url);

        $attachmentInfo = [
            'url' => $url,
            'title' => $title,
            'media_id' => null,
            'media_path' => null,
        ];

        // 如果需要下载附件
        if (!empty($this->options['download_attachments'])) {
            $mediaData = $this->downloadAttachment($url, $title);
            if ($mediaData) {
                $attachmentInfo['media_id'] = $mediaData['id'] ?? null;
                $attachmentInfo['media_path'] = $mediaData['file_path'] ?? null;
            } else {
                Log::warning('附件下载失败，仅记录原始URL: ' . $url);
            }
        }

        return $attachmentInfo;
    }

    /**
     * 串行下载附件（避免 curl_multi 的复杂性）
     *
     * @param array $attachments 附件列表
     *
     * @return array 下载结果列表
     */
    protected function downloadAttachments(array $attachments): array
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
                    Log::debug(
                        '下载附件（第 ' . $attempts . '/' . $maxRetries . ' 轮，共 ' . count($downloadUrls) . ' 个URL）: ' . $downloadUrl
                    );

                    // 使用MediaLibraryService下载远程文件
                    $result = $this->mediaLibraryService->downloadRemoteFile(
                        $downloadUrl,
                        $title,
                        $this->defaultAuthorId,
                        'admin'
                    );

                    $attemptDuration = round((microtime(true) - $attemptStartTime) * 1000, 2);

                    if (($result['code'] ?? null) === 0) {
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
                            'download_url' => $downloadUrl,
                            'base_url' => $baseUrl,
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

        $options['failed_media'] ??= [];

        $options['failed_media'][$url] = [
            'url' => $url,
            'title' => $title,
            'error' => $lastError,
            'retry_count' => ($options['failed_media'][$url]['retry_count'] ?? 0) + 1,
            'created_at' => $options['failed_media'][$url]['created_at'] ?? time(),
            'updated_at' => time(),
        ];

        // 更新import job的options字段
        $this->importJob->options = json_encode($options, JSON_UNESCAPED_UNICODE);
        $this->importJob->save();

        Log::info('已将失败媒体记录存储到任务选项中，URL: ' . $url);

        return null;
    }

    protected function convertHtmlToMarkdown(string $html, array $config = []): string
    {
        if ($html === '') {
            return '';
        }

        $config = $config + [
                'strip_tags' => false,
            ];

        // 基础清洗 WordPress 垃圾
        $html = preg_replace('/<!--\s*wp:.*?-->/', '', $html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $converter = $this->getHtmlToMarkdownConverter($config);

        $markdown = $converter->convert($html);

        return rtrim($markdown);
    }

    protected function getHtmlToMarkdownConverter(array $config): HtmlConverter
    {
        $key = md5(serialize($config));

        if (!isset($this->htmlToMarkdownConverters[$key])) {
            $this->htmlToMarkdownConverters[$key] = new HtmlConverter($config);
        }

        return $this->htmlToMarkdownConverters[$key];
    }

    protected function convertToUtf8(string $string): string
    {
        if ($string === '') {
            return $string;
        }

        // 1. 首先将字符串转换为二进制数据以便处理
        $binary = (string) $string;

        // 2. 移除 UTF-8 BOM（0xEF 0xBB 0xBF）
        if (str_starts_with(bin2hex($binary), 'efbbbf')) {
            $binary = substr($binary, 3);
        }

        // 3. 处理单独的 0xEF 字节（可能是不完整的 BOM 或其他无效序列）
        // 使用更严格的模式，确保只移除单独的 0xEF 字节
        $binary = preg_replace('/(?:\xef(?![\xbb\xbf]))/', '', $binary);

        // 4. 处理其他可能导致问题的单个字节
        // 移除任何不在有效 UTF-8 范围或单独出现的控制字符
        $binary = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $binary);

        // 5. 尝试检测编码并转换到 UTF-8
        $encoding = mb_detect_encoding(
            $binary,
            ['UTF-8', 'ASCII', 'GBK', 'GB2312', 'BIG5', 'Windows-1252', 'ISO-8859-1'],
            true
        );

        if ($encoding && $encoding !== 'UTF-8') {
            // 只有当检测到非 UTF-8 编码时才进行转换
            $string = mb_convert_encoding($binary, 'UTF-8', $encoding);
        } else {
            $string = $binary;
        }

        // 6. 确保字符串是有效的 UTF-8，如果无效则使用 iconv 进行清理
        if (!mb_check_encoding($string, 'UTF-8')) {
            // 使用 iconv 忽略无效字符
            $string = iconv('UTF-8', 'UTF-8//IGNORE', $string);

            // 如果 iconv 失败，尝试另一种方法
            if (!mb_check_encoding($string, 'UTF-8')) {
                $string = utf8_encode(utf8_decode($string));
            }
        }

        // 7. 清理所有控制字符（保留换行、回车、制表符）
        $string = preg_replace('/[^\P{C}\n\r\t]/u', '', $string);

        // 8. 最后再次确保是有效的 UTF-8
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = '';
        }

        return $string;
    }

    /**
     * 生成文章摘要：首先将内容转换为HTML，然后删除HTML标签
     *
     * @param string $content
     *
     * @return string
     * @throws CommonMarkException
     */
    protected function generateExcerpt(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $html = (string) $this->getExcerptConverter()->convert($content);

        // 保留基本换行语义
        $html = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $html);

        $text = strip_tags($html);
        $text = preg_replace('/\s+/u', ' ', trim($text));

        if (mb_strlen($text, 'UTF-8') <= 200) {
            return $text;
        }

        $slice = mb_substr($text, 0, 200, 'UTF-8');

        // 尝试按句子结束
        if (preg_match('/^(.+?[。！？.!?])/', $slice, $m)) {
            return $m[1];
        }

        return rtrim($slice) . '…';
    }

    /**
     * 获取 Markdown 转换器实例
     *
     * @return MarkdownConverter
     */
    protected function getExcerptConverter(): MarkdownConverter
    {
        if ($this->excerptConverter) {
            return $this->excerptConverter;
        }

        $config = [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
            'renderer' => [
                'soft_break' => "<br />\n",
            ],
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new TaskListExtension());

        return $this->excerptConverter = new MarkdownConverter($environment);
    }

    /**
     * 替换内容中的附件链接
     *
     * @param string $content     原始内容
     * @param string $contentType 内容类型（html / markdown）
     *
     * @return array [替换后的内容, 被引用的媒体ID列表]
     */
    protected function replaceAttachmentLinks(string $content, string $contentType): array
    {
        if ($content === '' || empty($this->attachmentMap)) {
            return [$content, []];
        }

        Log::debug('开始替换附件链接，内容类型: ' . $contentType . ', 附件数量: ' . count($this->attachmentMap));

        /**
         * baseUrl => ['new_url' => string, 'media_id' => int]
         */
        $baseUrlToNewUrl = [];

        /**
         * 构建 基础 URL → 新 URL 映射
         */
        foreach ($this->attachmentMap as $originalUrl => $attachmentInfo) {
            if (empty($attachmentInfo['id']) || empty($attachmentInfo['file_path'])) {
                continue;
            }

            $newUrl = '/uploads/' . ltrim($attachmentInfo['file_path'], '/');
            $filePath = public_path($newUrl);

            if (!file_exists($filePath)) {
                Log::warning('附件文件不存在，跳过: ' . $filePath);
                continue;
            }

            // 去 query / 去 scaled / 去尺寸
            $baseUrl = $this->getBaseAttachmentUrl($originalUrl) ?? strtok($originalUrl, '?');

            if (!$baseUrl) {
                continue;
            }

            // 去 -scaled
            $baseUrl = preg_replace('/-scaled(?=\.\w+$)/i', '', $baseUrl);

            $baseUrlToNewUrl[$baseUrl] = [
                'new_url' => $newUrl,
                'media_id' => $attachmentInfo['id'],
            ];
        }

        if (empty($baseUrlToNewUrl)) {
            return [$content, []];
        }

        /**
         * 执行一次、且只执行一次内容替换
         */
        if ($contentType === 'html') {
            $content = $this->replaceSizeVariantUrlsInHtml($content, $baseUrlToNewUrl);
        } else {
            $content = $this->replaceSizeVariantUrlsInMarkdown($content, $baseUrlToNewUrl);
        }

        /**
         * 从最终内容中反向统计被引用的媒体 ID
         */
        $referencedMediaIds = [];

        foreach ($baseUrlToNewUrl as $info) {
            if (str_contains($content, $info['new_url'])) {
                $referencedMediaIds[] = $info['media_id'];
            }
        }

        // 去重 + 保序
        $referencedMediaIds = array_values(array_unique($referencedMediaIds));

        Log::info('引用的媒体ID数量: ' . count($referencedMediaIds));

        return [$content, $referencedMediaIds];
    }

    /**
     * 格式化 Markdown 中“独占一行”的图片链接
     * 跳过代码块
     * 不破坏列表 / 引用 / 表格
     * 只保证图片行上下留空行
     */
    protected function formatMarkdownImages(string $markdown): string
    {
        $lines = preg_split('/\r?\n/', $markdown);
        $result = [];

        $inCodeBlock = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            // fenced code block 开关
            if (preg_match('/^```/', $trimmed)) {
                $inCodeBlock = !$inCodeBlock;
                $result[] = $line;
                continue;
            }

            if ($inCodeBlock) {
                $result[] = $line;
                continue;
            }

            // 仅匹配“整行只有图片”的情况
            if (preg_match('/^!\[[^\]]*\]\([^\)]+(?:\s+"[^"]*")?\)$/', $trimmed)) {
                // 上一行不是空行 → 补空行
                if (!empty($result) && trim(end($result)) !== '') {
                    $result[] = '';
                }

                $result[] = $trimmed;

                // 下一行不是空行 → 预留一个空行
                $result[] = '';
                continue;
            }

            $result[] = $line;
        }

        // 清理多余空行
        $text = implode("\n", $result);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * 在 HTML 内容中替换带尺寸参数的附件 URL（包括 src / href / data-* / srcset）
     *
     * @param string $content         HTML 内容
     * @param array  $baseUrlToNewUrl 基础 URL => ['new_url' => ..., 'media_id' => ...]
     *
     * @return string
     */
    protected function replaceSizeVariantUrlsInHtml(string $content, array $baseUrlToNewUrl): string
    {
        if ($content === '' || empty($baseUrlToNewUrl)) {
            return $content;
        }

        // 匹配常见携带 URL 的属性（不假设 HTML 结构）
        preg_match_all(
            '/\b(src|href|data-src|data-href|srcset)=["\']([^"\']+)["\']/i',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $attr = strtolower($match[1]);
            $rawValue = $match[2];

            if ($attr === 'srcset') {
                /**
                 * srcset 示例：
                 *   image-300x200.jpg 300w, image-768x512.jpg 768w
                 */
                $newValue = preg_replace_callback(
                    '/\s*([^,\s]+)(\s+\d+w)?/',
                    function ($m) use ($baseUrlToNewUrl) {
                        $url = $m[1];
                        $suffix = $m[2] ?? '';

                        $baseUrl = $this->getBaseAttachmentUrl($url);
                        if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                            return $baseUrlToNewUrl[$baseUrl]['new_url'] . $suffix;
                        }

                        return $m[0];
                    },
                    $rawValue
                );

                if ($newValue !== $rawValue) {
                    $content = str_replace(
                        [$attr . '="' . $rawValue . '"', $attr . "='" . $rawValue . "'"],
                        [$attr . '="' . $newValue . '"', $attr . "='" . $newValue . "'"],
                        $content
                    );
                }

                continue;
            }

            // 非 srcset：单一 URL
            $baseUrl = $this->getBaseAttachmentUrl($rawValue);
            if (!$baseUrl || !isset($baseUrlToNewUrl[$baseUrl])) {
                continue;
            }

            $newUrl = $baseUrlToNewUrl[$baseUrl]['new_url'];

            $content = str_replace(
                [$attr . '="' . $rawValue . '"', $attr . "='" . $rawValue . "'"],
                [$attr . '="' . $newUrl . '"', $attr . "='" . $newUrl . "'"],
                $content
            );

            Log::debug('HTML 尺寸 URL 替换', [
                'attr' => $attr,
                'from' => $rawValue,
                'to' => $newUrl,
            ]);
        }

        return $content;
    }

    /**
     * 在 Markdown 内容中替换 WordPress 附件 URL
     *
     * @param string $content
     * @param array  $baseUrlToNewUrl
     *
     * @return string
     */
    protected function replaceSizeVariantUrlsInMarkdown(string $content, array $baseUrlToNewUrl): string
    {
        if ($content === '' || empty($baseUrlToNewUrl)) {
            return $content;
        }

        Log::debug('进入 replaceSizeVariantUrlsInMarkdown，映射数: ' . count($baseUrlToNewUrl));

        // 已替换的原始片段
        $replaced = [];

        /**
         * 统一清洗 URL：
         * query
         * -scaled
         */
        $normalizeUrl = static function (string $url): string {
            $url = strtok($url, '?');

            return preg_replace('/-scaled(?=\.\w+$)/i', '', $url);
        };

        /**
         * 根据任意 URL 找到新 URL（支持尺寸图）
         */
        $resolveNewUrl = function (string $url) use ($baseUrlToNewUrl, $normalizeUrl): ?string {
            $cleanUrl = $normalizeUrl($url);

            // 1. 直接命中
            if (isset($baseUrlToNewUrl[$cleanUrl])) {
                return $baseUrlToNewUrl[$cleanUrl]['new_url'];
            }

            // 2. 尺寸图 → base
            $baseUrl = $this->getBaseAttachmentUrl($cleanUrl);
            if ($baseUrl && isset($baseUrlToNewUrl[$baseUrl])) {
                return $baseUrlToNewUrl[$baseUrl]['new_url'];
            }

            return null;
        };

        /**
         * 处理 Markdown 图片 + 普通链接
         */
        $pattern = '/(!?\[([^\]]*)\]\()(\S+?)(\s+"[^"]*")?\)/';

        $content = preg_replace_callback(
            $pattern,
            function ($m) use (&$replaced, $resolveNewUrl) {
                $fullMatch = $m[0];

                if (isset($replaced[$fullMatch])) {
                    return $fullMatch;
                }

                $prefix = $m[1]; // ![alt](
                $text = $m[2]; // alt / link text
                $url = $m[3];
                $title = $m[4] ?? '';

                $newUrl = $resolveNewUrl($url);
                if (!$newUrl) {
                    return $fullMatch;
                }

                $replacement = $prefix . $newUrl . $title . ')';
                $replaced[$fullMatch] = true;

                Log::debug('替换 Markdown URL: ' . $url . ' => ' . $newUrl);

                return $replacement;
            },
            $content
        );

        /**
         * 处理裸露的直接 URL（不在 Markdown 语法中）
         */
        $directUrlPattern = '/https?:\/\/[^\s\)"]+\.(?:jpe?g|png|gif|bmp|webp|svg|ico|tiff|tif)(?:-\d+x\d+)?(?:\?[^\s\)"]+)?/i';

        $content = preg_replace_callback(
            $directUrlPattern,
            function ($m) use (&$replaced, $resolveNewUrl) {
                $url = $m[0];

                if (isset($replaced[$url])) {
                    return $url;
                }

                $newUrl = $resolveNewUrl($url);
                if (!$newUrl) {
                    return $url;
                }

                $replaced[$url] = true;

                Log::debug('替换直接 URL: ' . $url . ' => ' . $newUrl);

                return $newUrl;
            },
            $content
        );

        return $content;
    }

    /**
     * 获取 WordPress 附件的原图 URL
     * - 支持尺寸图：-300x200
     * - 支持 scaled：-scaled / -scaled-300x200
     * - 支持 query / fragment
     * - 支持 jpg.webp / png.avif
     *
     * @param string $url
     *
     * @return string|null
     */
    protected function getBaseAttachmentUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? null;

        if (!$path) {
            return null;
        }

        // 是否包含 WP 尺寸或 scaled 特征
        if (!preg_match('/-(\d+)x(\d+)|-scaled/i', $path)) {
            return null;
        }

        // 处理双扩展：image.jpg.webp → image.jpg
        $path = preg_replace('/\.(jpe?g|png|gif|webp|avif)\.(webp|avif)$/i', '.$1', $path);

        // 移除 -scaled
        $path = preg_replace('/-scaled(?=\.\w+$)/i', '', $path);

        // 移除尺寸
        $path = preg_replace('/-(\d+)x(\d+)(?=\.\w+$)/i', '', $path);

        // 重建 URL
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        if (isset($parsed['scheme'], $parsed['host'])) {
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

            return "{$parsed['scheme']}://{$parsed['host']}{$port}{$path}{$query}{$fragment}";
        }

        return $path . $query . $fragment;
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
