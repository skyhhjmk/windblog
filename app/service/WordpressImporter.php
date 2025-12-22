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
     * @var ImportJob 导入任务实例
     */
    protected ImportJob $importJob;

    /**
     * @var array 导入选项
     */
    protected mixed $options;

    /**
     * @var int|null 默认作者 ID
     */
    protected mixed $defaultAuthorId;

    /**
     * @var MediaLibraryService 媒体库服务实例
     */
    protected MediaLibraryService $mediaLibraryService;

    /**
     * @var array 附件映射 [原始 URL => 媒体信息]
     */
    protected array $attachmentMap = [];

    /**
     * @var array WordPress 文章 ID 到新文章 ID 的映射
     */
    protected array $postIdMap = [];

    /**
     * @var array WordPress 评论 ID 到新评论 ID 的映射
     */
    protected array $commentIdMap = [];

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
     * 读取 XML 文件并处理编码问题
     */
    protected function readXmlFileWithEncoding($xmlFile): string
    {
        // 首先尝试直接读取文件
        $content = file_get_contents($xmlFile);

        // 检查文件是否以BOM开头，如果有则移除
        $bom = pack('H*', 'EFBBBF');
        if (strncmp($content, $bom, 3) === 0) {
            $content = substr($content, 3);
        }

        // 检测内容编码
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ASCII', 'ISO-8859-1'], true);

        // 如果不是UTF-8编码，则转换为UTF-8
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $content;
    }

    /**
     * 修复 XML 中的命名空间问题
     */
    protected function fixXmlNamespaces($xmlFile): string
    {
        try {
            Log::info('读取XML文件内容');
            $content = $this->readXmlFileWithEncoding($xmlFile);

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
     * 计算 XML 中项目总数
     */
    protected function countItems($xmlFile): int
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
     * 第一阶段：处理所有附件，建立完整的附件映射
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
                    $originalUrl = $result['original_url'];
                    $newUrl = '/uploads/' . $result['file_path'];
                    $mediaId = $result['id'];

                    // 1. 保存原始URL的映射
                    $this->attachmentMap[$originalUrl] = $result;
                    Log::debug('保存附件映射: ' . $originalUrl . ' => 媒体ID: ' . $mediaId);

                    // 2. 保存基础URL的映射（如果URL包含尺寸参数）
                    $baseUrl = $this->getBaseAttachmentUrl($originalUrl);
                    if ($baseUrl !== $originalUrl) {
                        $this->attachmentMap[$baseUrl] = $result;
                        Log::debug('保存基础URL映射: ' . $baseUrl . ' => 媒体ID: ' . $mediaId);
                    }

                    // 3. 如果URL包含域名，也保存不带域名的路径映射
                    $parsedUrl = parse_url($originalUrl);
                    if (isset($parsedUrl['path'])) {
                        $pathOnly = $parsedUrl['path'];
                        if (!isset($this->attachmentMap[$pathOnly])) {
                            $this->attachmentMap[$pathOnly] = $result;
                            Log::debug('保存路径映射: ' . $pathOnly . ' => 媒体ID: ' . $mediaId);
                        }

                        // 4. 如果路径包含尺寸参数，也保存基础路径的映射
                        $basePathUrl = $this->getBaseAttachmentUrl($pathOnly);
                        if ($basePathUrl !== $pathOnly && !isset($this->attachmentMap[$basePathUrl])) {
                            $this->attachmentMap[$basePathUrl] = $result;
                            Log::debug('保存基础路径映射: ' . $basePathUrl . ' => 媒体ID: ' . $mediaId);
                        }
                    }

                    // 5. 生成并保存可能的尺寸变体URL映射
                    $this->generateSizeVariantMappings($originalUrl, $result);
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

        Log::info('附件处理完成，共建立映射关系: ' . count($this->attachmentMap) . ' 个');
        Log::debug('附件映射表内容: ' . json_encode(array_keys($this->attachmentMap), JSON_UNESCAPED_UNICODE));

        $this->importJob->update([
            'progress' => 30,
            'message' => "第一阶段：附件处理完成 ({$processedAttachments}/{$attachmentCount})",
        ]);
    }

    /**
     * 生成并保存可能的尺寸变体URL映射
     *
     * @param string $originalUrl 原始URL
     * @param array  $result      附件信息
     *
     * @return void
     */
    protected function generateSizeVariantMappings(string $originalUrl, array $result): void
    {
        // 解析原始URL
        $parsedUrl = parse_url($originalUrl);
        $path = $parsedUrl['path'] ?? $originalUrl;
        $pathInfo = pathinfo($path);
        $dirname = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';

        // 检查原始文件名是否已经包含尺寸参数
        // WordPress缩略图格式：filename-{width}x{height}.ext
        // 例如：image-1706301607-1024x526.png, photo-150x150.jpg
        // 注意：需要区分时间戳（通常是10位数字）和尺寸参数（通常是2-4位数字x2-4位数字）
        // 时间戳格式：1746177998（10位）
        // 尺寸格式：1024x526, 150x150, 300x200 等（宽高各2-4位）

        // 改进的正则：只匹配末尾的尺寸参数，尺寸数字限制在2-4位，避免误匹配时间戳
        // 支持多个连续的尺寸后缀（如 -1024x526-150x150）
        $sizePattern = '/(-\\d{2,4}x\\d{2,4})+$/i';

        $baseFilename = $filename;
        $hasSizeInOriginal = false;

        if (preg_match($sizePattern, $filename, $matches)) {
            // 原始URL包含尺寸参数，提取基础文件名
            $baseFilename = preg_replace($sizePattern, '', $filename);
            $hasSizeInOriginal = true;

            // 提取最后一个尺寸参数用于日志
            preg_match('/(\\d{2,4})x(\\d{2,4})$/', $filename, $sizeMatches);
            $originalWidth = $sizeMatches[1] ?? '';
            $originalHeight = $sizeMatches[2] ?? '';

            Log::debug('检测到原始URL包含尺寸参数: ' . $originalWidth . 'x' . $originalHeight . ', 基础文件名: ' . $baseFilename);

            // 保存不带尺寸参数的基础URL映射
            $basePath = $dirname . '/' . $baseFilename . '.' . $extension;

            if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
                $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $basePath;
            } else {
                $baseUrl = $basePath;
            }

            if (!isset($this->attachmentMap[$baseUrl])) {
                $this->attachmentMap[$baseUrl] = $result;
                Log::debug('保存基础文件名映射: ' . $baseUrl . ' => 媒体ID: ' . $result['id']);
            }

            if (!isset($this->attachmentMap[$basePath])) {
                $this->attachmentMap[$basePath] = $result;
                Log::debug('保存基础文件名路径映射: ' . $basePath . ' => 媒体ID: ' . $result['id']);
            }
        }

        // 生成通配符模式，匹配所有可能的尺寸变体
        // 使用正则表达式匹配 {baseFilename}-{任意数字}x{任意数字}.{extension}
        // 这样可以匹配任何尺寸的变体，而不是固定列表

        // 构建基础路径（不含文件名）
        $baseDir = $dirname;
        if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
            $baseUrlPrefix = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $baseDir . '/';
        } else {
            $baseUrlPrefix = $baseDir . '/';
        }

        // 保存一个特殊的通配符映射，用于后续匹配
        // 格式：{baseUrlPrefix}{baseFilename}-*x*.{extension}
        $wildcardPattern = $baseUrlPrefix . $baseFilename . '-(\d+)x(\d+).' . $extension;

        // 将通配符模式保存到映射表中，使用特殊标记
        $wildcardKey = '__PATTERN__' . $wildcardPattern;
        if (!isset($this->attachmentMap[$wildcardKey])) {
            $this->attachmentMap[$wildcardKey] = array_merge($result, [
                'is_pattern' => true,
                'pattern' => $wildcardPattern,
                'base_filename' => $baseFilename,
                'base_dir' => $baseDir,
                'extension' => $extension,
                'base_url_prefix' => $baseUrlPrefix,
            ]);
            Log::debug('保存通配符模式映射: ' . $wildcardPattern . ' => 媒体ID: ' . $result['id']);
        }

        // 同时保存路径版本的通配符模式
        $pathWildcardPattern = $baseDir . '/' . $baseFilename . '-(\d+)x(\d+).' . $extension;
        $pathWildcardKey = '__PATTERN__' . $pathWildcardPattern;
        if (!isset($this->attachmentMap[$pathWildcardKey])) {
            $this->attachmentMap[$pathWildcardKey] = array_merge($result, [
                'is_pattern' => true,
                'pattern' => $pathWildcardPattern,
                'base_filename' => $baseFilename,
                'base_dir' => $baseDir,
                'extension' => $extension,
                'base_url_prefix' => $baseDir . '/',
            ]);
            Log::debug('保存路径通配符模式映射: ' . $pathWildcardPattern . ' => 媒体ID: ' . $result['id']);
        }

        // 如果原始URL不包含尺寸参数，也生成通配符模式
        // 这样可以匹配该文件的所有尺寸变体
        if (!$hasSizeInOriginal) {
            $wildcardPatternAlt = $baseUrlPrefix . $filename . '-(\d+)x(\d+).' . $extension;
            $wildcardKeyAlt = '__PATTERN__' . $wildcardPatternAlt;
            if (!isset($this->attachmentMap[$wildcardKeyAlt])) {
                $this->attachmentMap[$wildcardKeyAlt] = array_merge($result, [
                    'is_pattern' => true,
                    'pattern' => $wildcardPatternAlt,
                    'base_filename' => $filename,
                    'base_dir' => $baseDir,
                    'extension' => $extension,
                    'base_url_prefix' => $baseUrlPrefix,
                ]);
                Log::debug('保存备用通配符模式映射: ' . $wildcardPatternAlt . ' => 媒体ID: ' . $result['id']);
            }

            // 路径版本
            $pathWildcardPatternAlt = $baseDir . '/' . $filename . '-(\d+)x(\d+).' . $extension;
            $pathWildcardKeyAlt = '__PATTERN__' . $pathWildcardPatternAlt;
            if (!isset($this->attachmentMap[$pathWildcardKeyAlt])) {
                $this->attachmentMap[$pathWildcardKeyAlt] = array_merge($result, [
                    'is_pattern' => true,
                    'pattern' => $pathWildcardPatternAlt,
                    'base_filename' => $filename,
                    'base_dir' => $baseDir,
                    'extension' => $extension,
                    'base_url_prefix' => $baseDir . '/',
                ]);
                Log::debug('保存备用路径通配符模式映射: ' . $pathWildcardPatternAlt . ' => 媒体ID: ' . $result['id']);
            }
        }
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
     * @return array|null 返回附件信息，包含url、title和id（如果下载成功）
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
            'id' => null,
            'file_path' => null,
        ];

        // 如果需要下载附件
        if (!empty($this->options['download_attachments'])) {
            $mediaData = $this->downloadAttachment($url, $title);
            if ($mediaData) {
                $attachmentInfo['id'] = $mediaData['id'] ?? null;
                $attachmentInfo['file_path'] = $mediaData['file_path'] ?? null;
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
        if (empty($content)) {
            return [$content, []];
        }

        Log::debug('开始替换附件链接，内容类型: ' . $contentType . ', 附件映射数量: ' . count($this->attachmentMap));

        $referencedMediaIds = [];

        // 第一步：提取内容中所有的图片URL
        $imageUrls = $this->extractImageUrls($content, $contentType);
        Log::debug('从内容中提取到 ' . count($imageUrls) . ' 个图片URL');

        // 第二步：处理提取到的图片URL，下载不在映射表中的图片
        foreach ($imageUrls as $imageUrl) {
            // 标准化URL（移除查询参数和锚点）
            $cleanUrl = $this->cleanUrl($imageUrl);

            // 检查是否已在映射表中
            if (!$this->isUrlInAttachmentMap($cleanUrl)) {
                Log::info('发现未映射的图片URL，尝试下载: ' . $cleanUrl);

                // 尝试下载图片
                $mediaData = $this->downloadAttachment($cleanUrl, basename($cleanUrl));

                if ($mediaData && !empty($mediaData['id'])) {
                    // 将下载的图片添加到映射表
                    $this->attachmentMap[$cleanUrl] = $mediaData;

                    // 同时添加原始URL（带查询参数）的映射
                    if ($cleanUrl !== $imageUrl) {
                        $this->attachmentMap[$imageUrl] = $mediaData;
                    }

                    // 生成尺寸变体映射
                    $this->generateSizeVariantMappings($cleanUrl, $mediaData);

                    Log::info('成功下载并映射图片: ' . $cleanUrl . ' => 媒体ID: ' . $mediaData['id']);
                } else {
                    Log::warning('图片下载失败: ' . $cleanUrl);
                }
            }
        }

        // 分离普通映射和模式映射
        $normalMappings = [];
        $patternMappings = [];

        foreach ($this->attachmentMap as $key => $value) {
            if (strpos($key, '__PATTERN__') === 0) {
                $patternMappings[] = $value;
            } else {
                $normalMappings[$key] = $value;
            }
        }

        Log::debug('普通映射数量: ' . count($normalMappings) . ', 模式映射数量: ' . count($patternMappings));

        // 按URL长度降序排序普通映射，优先替换长URL
        uksort($normalMappings, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        // 第三步：使用普通映射进行精确替换
        foreach ($normalMappings as $originalUrl => $attachmentInfo) {
            if (empty($attachmentInfo['id']) || empty($attachmentInfo['file_path'])) {
                continue;
            }

            $newUrl = '/uploads/' . $attachmentInfo['file_path'];
            $mediaId = $attachmentInfo['id'];

            // 检查内容中是否包含这个URL（大小写不敏感）
            if (stripos($content, $originalUrl) === false) {
                continue;
            }

            // 记录被引用的媒体ID
            if (!in_array($mediaId, $referencedMediaIds)) {
                $referencedMediaIds[] = $mediaId;
                Log::debug('添加被引用媒体ID: ' . $mediaId . ' (URL: ' . $originalUrl . ')');
            }

            // 执行替换
            $content = $this->replaceUrlInContent($content, $originalUrl, $newUrl, $contentType);
            Log::debug('精确替换URL: ' . $originalUrl . ' => ' . $newUrl);
        }

        // 第四步：使用模式映射进行通配符替换
        foreach ($patternMappings as $patternInfo) {
            if (empty($patternInfo['id']) || empty($patternInfo['file_path'])) {
                continue;
            }

            $newUrl = '/uploads/' . $patternInfo['file_path'];
            $mediaId = $patternInfo['id'];

            // 优先使用存储的组件构建正则表达式，避免 preg_quote 带来的双重转义问题
            if (!empty($patternInfo['base_url_prefix']) && !empty($patternInfo['base_filename']) && !empty($patternInfo['extension'])) {
                // 构建正则表达式：{prefix}{filename}-\d{2,4}x\d{2,4}(-\d{2,4}x\d{2,4})*\.{extension}
                // 注意：尺寸数字限制在2-4位，避免误匹配时间戳（通常是10位数字）
                // WordPress缩略图尺寸通常在10-9999之间，如：150x150, 1024x526, 300x200
                $regex = '/' . preg_quote($patternInfo['base_url_prefix'] . $patternInfo['base_filename'], '/')
                    . '(-\\d{2,4}x\\d{2,4})+' . preg_quote('.' . $patternInfo['extension'], '/') . '/i';
            } elseif (!empty($patternInfo['pattern'])) {
                // 回退到基于 pattern 字段构建 regex
                $regex = '/' . preg_quote($patternInfo['pattern'], '/') . '/i';
                // 尝试修复 pattern 中的 (\d+)x(\d+) 部分
                $regex = str_replace(['\(\\d\+\)x\(\\d\+\)', '\(\\\d\+\)x\(\\\d\+\)'], '(\d+)x(\d+)', $regex);
            } else {
                continue;
            }

            // 查找所有匹配的URL
            if (preg_match_all($regex, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $matchedUrl = $match[0];

                    // 记录被引用的媒体ID
                    if (!in_array($mediaId, $referencedMediaIds)) {
                        $referencedMediaIds[] = $mediaId;
                        Log::debug('添加被引用媒体ID: ' . $mediaId . ' (模式匹配URL: ' . $matchedUrl . ')');
                    }

                    // 执行替换
                    $content = $this->replaceUrlInContent($content, $matchedUrl, $newUrl, $contentType);
                    Log::debug('模式匹配替换URL: ' . $matchedUrl . ' => ' . $newUrl);
                }
            }
        }

        // 格式化markdown内容中的图片链接
        if ($contentType === 'markdown') {
            $content = $this->formatMarkdownImages($content);
        }

        return [$content, $referencedMediaIds];
    }

    /**
     * 从内容中提取所有图片URL
     *
     * @param string $content     内容
     * @param string $contentType 内容类型
     *
     * @return array 图片URL数组
     */
    protected function extractImageUrls(string $content, string $contentType): array
    {
        $urls = [];

        if ($contentType === 'html') {
            // 提取HTML中的图片URL
            // 匹配 <img> 标签的 src 属性
            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
            if (!empty($matches[1])) {
                $urls = array_merge($urls, $matches[1]);
            }

            // 匹配 srcset 属性
            preg_match_all('/srcset=["\']([^"\']+)["\']/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $srcset) {
                    // srcset 可能包含多个URL，用逗号分隔
                    $srcsetUrls = explode(',', $srcset);
                    foreach ($srcsetUrls as $srcsetUrl) {
                        // 提取URL部分（去除尺寸描述符）
                        $srcsetUrl = trim(preg_replace('/\s+\d+[wx]$/', '', trim($srcsetUrl)));
                        if (!empty($srcsetUrl)) {
                            $urls[] = $srcsetUrl;
                        }
                    }
                }
            }

            // 匹配 data-src 属性（懒加载）
            preg_match_all('/data-src=["\']([^"\']+)["\']/', $content, $matches);
            if (!empty($matches[1])) {
                $urls = array_merge($urls, $matches[1]);
            }
        } else {
            // 提取Markdown中的图片URL
            // 匹配 ![alt](url) 格式
            preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/i', $content, $matches);
            if (!empty($matches[2])) {
                $urls = array_merge($urls, $matches[2]);
            }

            // 匹配直接的图片URL（以常见图片扩展名结尾，支持WordPress缩略图后缀）
            preg_match_all('/https?:\/\/[^\s\)]+?(?:-\d+x\d+)*\.(?:jpe?g|png|gif|bmp|webp|svg|ico|tiff?|JPG|PNG|GIF|WEBP)(?:\?[^\s\)]*)?/i', $content, $matches);
            if (!empty($matches[0])) {
                $urls = array_merge($urls, $matches[0]);
            }
        }

        // 去重并过滤空URL
        $urls = array_unique(array_filter($urls));

        // 只保留外部URL（http/https开头）
        $urls = array_filter($urls, function ($url) {
            return preg_match('/^https?:\/\//i', $url);
        });

        return array_values($urls);
    }

    /**
     * 清理URL，移除查询参数和锚点
     *
     * @param string $url 原始URL
     *
     * @return string 清理后的URL
     */
    protected function cleanUrl(string $url): string
    {
        $parsedUrl = parse_url($url);

        $cleanUrl = '';
        if (isset($parsedUrl['scheme'])) {
            $cleanUrl .= $parsedUrl['scheme'] . '://';
        }
        if (isset($parsedUrl['host'])) {
            $cleanUrl .= $parsedUrl['host'];
        }
        if (isset($parsedUrl['path'])) {
            $cleanUrl .= $parsedUrl['path'];
        }

        return $cleanUrl;
    }

    /**
     * 检查URL是否在附件映射表中
     *
     * @param string $url URL
     *
     * @return bool
     */
    protected function isUrlInAttachmentMap(string $url): bool
    {
        // 直接检查
        if (isset($this->attachmentMap[$url])) {
            return true;
        }

        // 检查基础URL
        $baseUrl = $this->getBaseAttachmentUrl($url);
        if ($baseUrl !== $url && isset($this->attachmentMap[$baseUrl])) {
            return true;
        }

        // 检查路径（不含域名）
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['path'])) {
            $pathOnly = $parsedUrl['path'];
            if (isset($this->attachmentMap[$pathOnly])) {
                return true;
            }

            // 检查基础路径
            $basePathUrl = $this->getBaseAttachmentUrl($pathOnly);
            if ($basePathUrl !== $pathOnly && isset($this->attachmentMap[$basePathUrl])) {
                return true;
            }
        }

        // 检查模式匹配
        foreach ($this->attachmentMap as $key => $value) {
            if (strpos($key, '__PATTERN__') === 0 && !empty($value['id'])) {
                if (!empty($value['base_url_prefix']) && !empty($value['base_filename']) && !empty($value['extension'])) {
                    // 构建正则表达式：{prefix}{filename}-\d+x\d+(-\d+x\d+)*\.{extension}
                    $regex = '/' . preg_quote($value['base_url_prefix'] . $value['base_filename'], '/')
                        . '(-\d+x\d+)+' . preg_quote('.' . $value['extension'], '/') . '/i';
                } elseif (!empty($value['pattern'])) {
                    $regex = '/' . preg_quote($value['pattern'], '/') . '/i';
                    $regex = str_replace(['\(\\d\+\)x\(\\d\+\)', '\(\\\d\+\)x\(\\\d\+\)'], '(\d+)x(\d+)', $regex);
                } else {
                    continue;
                }

                if (preg_match($regex, $url)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 在内容中替换URL
     *
     * @param string $content     内容
     * @param string $originalUrl 原始URL
     * @param string $newUrl      新URL
     * @param string $contentType 内容类型
     *
     * @return string 替换后的内容
     */
    protected function replaceUrlInContent(string $content, string $originalUrl, string $newUrl, string $contentType): string
    {
        if ($contentType === 'html') {
            // HTML替换
            $patterns = [
                '/(<img[^>]*?src=["\'])' . preg_quote($originalUrl, '/') . '(["\'][^>]*?>)/i',
                '/(<a[^>]*?href=["\'])' . preg_quote($originalUrl, '/') . '(["\'][^>]*?>)/i',
                '/(srcset=["\'][^"\']*?)' . preg_quote($originalUrl, '/') . '([^"\']*?["\'])/i',
                '/(data-src=["\'])' . preg_quote($originalUrl, '/') . '(["\'])/i',
            ];

            foreach ($patterns as $pattern) {
                $content = preg_replace($pattern, '${1}' . $newUrl . '${2}', $content);
            }
        } else {
            // Markdown 替换
            $quotedUrl = preg_quote($originalUrl, '/');

            // 图片链接替换
            $imagePattern = '/!\\[([^\\]]*)\\]\\(' . $quotedUrl . '(?:\\s+\"[^\"]*\")?\\)/i';
            $content = preg_replace($imagePattern, '![$1](' . $newUrl . ')', $content);

            // 普通链接替换
            $linkPattern = '/\\[([^\\]]*)\\]\\(' . $quotedUrl . '(?:\\s+\\"[^\\"]*\\")?\\)/i';
            $content = preg_replace($linkPattern, '[$1](' . $newUrl . ')', $content);

            // 直接 URL 替换
            $content = str_ireplace($originalUrl, $newUrl, $content);
        }

        return $content;
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
     * 获取基础附件URL，移除尺寸参数
     *
     * @param string $url 原始URL
     *
     * @return string 基础URL（如果没有尺寸参数则返回原URL）
     */
    protected function getBaseAttachmentUrl(string $url): string
    {
        // 解析URL
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? $url;
        $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        // 获取文件名和扩展名
        $pathInfo = pathinfo($path);
        $dirname = $pathInfo['dirname'] ?? '';
        $filename = $pathInfo['filename'] ?? '';
        $extension = $pathInfo['extension'] ?? '';

        if (empty($filename) || empty($extension)) {
            Log::debug("URL不包含有效的文件名或扩展名，返回原URL: $url");

            return $url;
        }

        // WordPress缩略图尺寸参数的正则模式
        // 匹配末尾的 -数字x数字 格式，支持多个连续的尺寸参数（如 -1024x526-150x150）
        // 注意：必须确保匹配的是真正的尺寸参数，而不是文件名中的时间戳
        // WordPress尺寸参数特点：宽度和高度通常在50-4000之间，且宽高比例合理

        // 首先尝试移除所有末尾的尺寸参数（支持多个连续的尺寸后缀）
        $sizePattern = '/(-\d{2,4}x\d{2,4})+$/i';

        if (preg_match($sizePattern, $filename, $matches)) {
            // 找到尺寸参数，移除它们
            $baseFilename = preg_replace($sizePattern, '', $filename);

            // 确保移除后的文件名不为空
            if (!empty($baseFilename)) {
                $basePath = $dirname . '/' . $baseFilename . '.' . $extension;

                // 重新构建完整URL
                if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
                    $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $basePath;
                } else {
                    $baseUrl = $basePath;
                }

                Log::debug("提取基础URL (移除尺寸参数): $url => $baseUrl (移除了: {$matches[0]})");

                return $baseUrl;
            }
        }

        // 处理包含时间戳和尺寸参数的特殊情况，例如：image-1746177998-1024x526.png
        // 这种情况下，只移除尺寸参数，保留时间戳
        $timestampSizePattern = '/(-\d{2,4}x\d{2,4})+$/i';
        if (preg_match($timestampSizePattern, $filename, $matches)) {
            // 找到尺寸参数，移除它们
            $baseFilename = preg_replace($timestampSizePattern, '', $filename);

            // 确保移除后的文件名不为空
            if (!empty($baseFilename)) {
                $basePath = $dirname . '/' . $baseFilename . '.' . $extension;

                // 重新构建完整URL
                if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
                    $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $basePath;
                } else {
                    $baseUrl = $basePath;
                }

                Log::debug("提取基础URL (移除尺寸参数): $url => $baseUrl (移除了: {$matches[0]})");

                return $baseUrl;
            }
        }

        // 没有找到尺寸参数，返回原URL
        Log::debug("URL不包含尺寸参数，返回原URL: $url");

        return $url;
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
