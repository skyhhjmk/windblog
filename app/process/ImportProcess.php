<?php

namespace app\process;

use app\model\ImportJob;
use app\service\MQService;
use app\service\WordpressImporter;
use Exception;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

class ImportProcess
{
    /**
     * 检查间隔（秒）
     *
     * @var int
     */
    protected int $interval = 60;

    /**
     * 定时器
     *
     * @var int
     */
    protected int $timerId;

    /**
     * 当前处理中的任务列表
     *
     * @var array
     */
    protected array $currentJobs = [];

    /**
     * 最大并发任务数
     *
     * @var int
     */
    protected int $maxConcurrentJobs = 5;

    // MQ
    protected $mqChannel = null;

    protected string $exchange = 'import_exchange';

    protected string $routingKey = 'import_job';

    protected string $queueName = 'import_queue';

    protected string $dlxExchange = 'import_dlx_exchange';

    protected string $dlxQueue = 'import_dlx_queue';

    /**
     * 构造函数
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * 进程启动时执行
     *
     * @param Worker $worker
     *
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        // 检查系统是否已安装
        if (!is_installed()) {
            Log::info('系统未安装，跳过导入队列消费');

            return;
        }

        try {
            // 覆盖命名（可通过配置）
            $this->exchange = (string) blog_config('rabbitmq_import_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_import_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_import_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_import_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlxQueue = (string) blog_config('rabbitmq_import_dlx_queue', $this->dlxQueue, true) ?: $this->dlxQueue;

            // 使用 MQService 初始化本进程专属交换机/队列/死信
            $this->mqChannel = MQService::getChannel();
            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlxQueue);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlxQueue);

            // 订阅队列
            $this->mqChannel->basic_consume(
                $this->queueName,
                '',
                false,
                false,
                false,
                false,
                [$this, 'handleMessage']
            );

            // 轮询消费消息队列（实时处理）
            if (class_exists(Timer::class)) {
                $this->timerId = Timer::add(1, function () {
                    try {
                        if ($this->mqChannel === null) {
                            Log::warning('ImportProcess: channel is null, reconnecting...');
                            $this->reconnectMq();

                            return;
                        }
                        if ($this->mqChannel->is_consuming()) {
                            $this->mqChannel->wait(null, false, 1.0);
                        }
                    } catch (AMQPTimeoutException $e) {
                        // 正常超时
                    } catch (Throwable $e) {
                        $errorMsg = $e->getMessage();
                        Log::warning('ImportProcess 消费轮询异常: ' . $errorMsg);
                        // 检测通道连接断开，触发自愈
                        if (str_contains($errorMsg, 'Channel connection is closed') ||
                            str_contains($errorMsg, 'Broken pipe') ||
                            str_contains($errorMsg, 'connection is closed') ||
                            str_contains($errorMsg, 'on null')) {
                            Log::warning('ImportProcess 检测到连接断开，尝试重建连接');
                            $this->reconnectMq();
                        }
                    }
                });
            }

            // 每60分钟进行一次数据库轮询（兜底机制）
            if (class_exists(Timer::class)) {
                Timer::add(3600, function () { // 60分钟 = 3600秒
                    try {
                        $this->pollDatabaseForPendingJobs();
                    } catch (Throwable $e) {
                        Log::warning('数据库轮询异常: ' . $e->getMessage());
                    }
                });
            }

            // 每60秒进行一次 MQ 健康检查
            if (class_exists(Timer::class)) {
                Timer::add(60, function () {
                    try {
                        MQService::checkAndHeal();
                    } catch (Throwable $e) {
                        Log::warning('MQ 健康检查异常(ImportProcess): ' . $e->getMessage());
                    }
                });
            }

            Log::info('ImportProcess 队列初始化成功，数据库轮询已启动');
        } catch (Throwable $e) {
            Log::error('ImportProcess 队列初始化失败: ' . $e->getMessage());
        }

        // 保留历史卡住任务检查
        $this->checkStuckJobs();
    }

    /**
     * 检查是否有卡住的任务
     *
     * @return void
     */
    protected function checkStuckJobs(): void
    {
        try {
            // 查找长时间处于processing状态的任务（超过1小时）
            $stuckJobs = ImportJob::where('status', 'processing')
                ->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-1 hour')))
                ->get();

            foreach ($stuckJobs as $job) {
                Log::warning('发现卡住的导入任务，重置状态为pending: ID=' . $job->id . ', 文件=' . $job->name);
                $job->update([
                    'status' => 'pending',
                    'message' => '长时间处于processing状态，任务被重置',
                ]);
            }
        } catch (Exception $e) {
            Log::error('检查卡住的任务时出错: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * 数据库轮询检查待处理任务
     *
     * @return void
     */
    protected function pollDatabaseForPendingJobs(): void
    {
        try {
            Log::info('开始数据库轮询检查待处理导入任务');

            // 查找所有pending状态的任务
            $pendingJobs = ImportJob::where('status', 'pending')
                ->where(function ($query) {
                    // 排除最近5分钟内已执行过的任务（防止重复处理）
                    $query->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-5 minutes')))
                        ->orWhereNull('updated_at');
                })
                ->orderBy('created_at', 'asc')
                ->get();

            if ($pendingJobs->isEmpty()) {
                Log::info('数据库轮询：未发现待处理任务');

                return;
            }

            Log::info('数据库轮询：发现 ' . $pendingJobs->count() . ' 个待处理任务');

            // 记录详细的任务信息用于调试
            foreach ($pendingJobs as $job) {
                Log::debug("待处理任务详情: ID={$job->id}, 文件={$job->name}, 创建时间={$job->created_at}, 更新时间={$job->updated_at}");
            }

            // 计算可处理的任务数量
            $availableSlots = $this->maxConcurrentJobs - count($this->currentJobs);
            if ($availableSlots <= 0) {
                Log::info('当前并发任务数已达上限，跳过数据库轮询');

                return;
            }

            // 只处理可用槽位数量的任务
            $jobsToProcess = $pendingJobs->take($availableSlots);
            Log::info('将处理 ' . $jobsToProcess->count() . ' 个任务，当前并发数: ' . count($this->currentJobs) . '/' . $this->maxConcurrentJobs);

            foreach ($jobsToProcess as $job) {
                try {
                    // 使用数据库事务和乐观锁，确保任务不会被重复处理
                    $updated = ImportJob::where('id', $job->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'processing',
                            'message' => '数据库轮询开始处理',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);

                    if (!$updated) {
                        Log::info("任务 {$job->id} 已被其他进程处理或状态已改变，跳过");
                        continue;
                    }

                    Log::info('数据库轮询开始处理任务: ID=' . $job->id . ', 文件=' . $job->name);

                    // 重新获取更新后的任务
                    $updatedJob = ImportJob::find($job->id);
                    if (!$updatedJob) {
                        Log::warning("任务 {$job->id} 不存在，跳过");
                        continue;
                    }

                    // 执行导入任务
                    $this->executeImportJob($updatedJob);

                } catch (Throwable $e) {
                    Log::error('数据库轮询处理任务失败: ID=' . $job->id . ', 错误: ' . $e->getMessage());

                    // 更新任务状态为failed
                    ImportJob::where('id', $job->id)->update([
                        'status' => 'failed',
                        'message' => '数据库轮询处理失败: ' . $e->getMessage(),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            Log::info('数据库轮询处理完成');
        } catch (Throwable $e) {
            Log::error('数据库轮询异常: ' . $e->getMessage());
        }
    }

    /**
     * 执行导入任务
     *
     * @param ImportJob $job 导入任务对象
     *
     * @return bool 执行结果
     */
    protected function executeImportJob(ImportJob $job): bool
    {
        try {
            // 将任务添加到当前处理列表
            $this->currentJobs[$job->id] = $job;
            Log::info('开始处理导入任务: ' . $job->name . ', ID: ' . $job->id . ', 当前并发数: ' . count($this->currentJobs));

            // 执行导入
            $result = false;
            switch ($job->type) {
                case 'wordpress_xml':
                    Log::info('开始处理WordPress XML导入任务: ' . $job->name);
                    $importer = new WordpressImporter($job);
                    // 定期发送心跳，防止连接超时
                    $heartbeatTimer = null;
                    if (class_exists(Timer::class)) {
                        // 每30秒发送一次心跳
                        $heartbeatTimer = Timer::add(30, function () {
                            try {
                                // 发送MQ心跳，保持连接活跃
                                if ($this->mqChannel && $this->mqChannel->is_consuming()) {
                                    // 执行一个轻量操作来保持连接活跃
                                    $this->mqChannel->basic_qos(0, 1, false);
                                }
                            } catch (Throwable $e) {
                                Log::warning('发送心跳失败: ' . $e->getMessage());
                            }
                        });
                    }

                    // 执行导入
                    $result = $importer->execute();

                    // 清除心跳定时器
                    if ($heartbeatTimer) {
                        Timer::del($heartbeatTimer);
                    }

                    $job->update([
                        'status' => $result ? 'completed' : 'failed',
                        'progress' => 100,
                        'message' => $result ? '导入完成' : '导入失败',
                    ]);
                    Log::info('WordPress XML导入任务处理完成: ' . $job->name);
                    break;
                default:
                    $errorMessage = '未知的导入类型: ' . $job->type;
                    Log::error($errorMessage);
                    $job->update([
                        'status' => 'failed',
                        'message' => $errorMessage,
                    ]);
                    $result = false;
                    break;
            }

            // 从当前处理列表中移除任务
            unset($this->currentJobs[$job->id]);
            Log::info('导入任务处理完成: ' . $job->name . ', ID: ' . $job->id . ', 当前并发数: ' . count($this->currentJobs));

            return $result;
        } catch (Throwable $e) {
            Log::error('导入任务执行错误: ' . $e->getMessage());

            // 更新任务状态为failed
            $job->update([
                'status' => 'failed',
                'message' => '导入执行错误: ' . $e->getMessage(),
            ]);

            // 从当前处理列表中移除任务
            unset($this->currentJobs[$job->id]);
            Log::info('导入任务执行失败: ' . $job->name . ', ID: ' . $job->id . ', 当前并发数: ' . count($this->currentJobs));

            return false;
        }
    }

    /**
     * 消费并处理导入消息
     */
    public function handleMessage(AMQPMessage $message): void
    {
        try {
            // 检查当前并发任务数是否超过限制
            if (count($this->currentJobs) >= $this->maxConcurrentJobs) {
                Log::warning('当前并发任务数已达上限: ' . count($this->currentJobs) . '/' . $this->maxConcurrentJobs . ', 消息将被重新排队');
                // 拒绝消息，让它重新排队
                $message->nack(false, true);

                return;
            }

            $data = json_decode($message->getBody(), true) ?: [];
            if (empty($data['type']) || empty($data['file_path']) || empty($data['name'])) {
                Log::warning('ImportProcess 收到无效消息: ' . $message->getBody());
                $message->ack();

                return;
            }

            // 检查是否已有对应的数据库记录
            $job = null;
            if (!empty($data['job_id'])) {
                // 使用数据库事务和乐观锁，确保任务不会被重复处理
                $updated = ImportJob::where('id', $data['job_id'])
                    ->whereIn('status', ['pending', 'failed'])
                    ->update([
                        'status' => 'processing',
                        'message' => '消息队列开始处理',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                if ($updated) {
                    $job = ImportJob::find($data['job_id']);
                } else {
                    // 检查任务是否已经在处理中
                    $existingJob = ImportJob::find($data['job_id']);
                    if ($existingJob && $existingJob->status === 'processing') {
                        Log::info("任务 {$data['job_id']} 已经在处理中，跳过");
                        $message->ack();

                        return;
                    }
                }
            }

            // 如果没有找到对应的数据库记录，或者更新失败，则创建新的记录
            if (!$job) {
                $job = new ImportJob();
                $job->name = (string) $data['name'];
                $job->type = (string) $data['type'];
                $job->file_path = (string) $data['file_path'];
                $job->author_id = isset($data['author_id']) ? (int) $data['author_id'] : null;
                $job->options = json_encode($data['options'] ?? [], JSON_UNESCAPED_UNICODE);
                $job->status = 'processing';
                $job->progress = 0;
                $job->message = '消息队列开始处理';
                $job->save();
            }

            // 立即ack消息，避免因为心跳超时导致消息重新排队
            $message->ack();
            Log::info('消息已ack，开始处理任务: ' . $job->name . ', ID: ' . $job->id);

            // 执行导入任务
            $this->executeImportJob($job);
        } catch (Throwable $e) {
            Log::error('导入任务执行错误: ' . $e->getMessage());
            // 已经ack过的消息不会重新排队，所以这里不需要nack
        }
    }

    /**
     * 进程停止时执行
     *
     * @param Worker $worker
     *
     * @return void
     */
    public function onWorkerStop(Worker $worker): void
    {
        // 清除定时器
        if (isset($this->timerId)) {
            Timer::del($this->timerId);
        }

        // 记录最终内存使用情况
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;
        Log::info("WP 导入进程已停止 - 最终内存使用: {$memoryUsage}MB, 峰值内存: {$memoryPeak}MB");
    }

    /**
     * 重建MQ连接和队列绑定
     */
    protected function reconnectMq(): void
    {
        try {
            // 关闭现有的通道
            if ($this->mqChannel !== null) {
                try {
                    $this->mqChannel->close();
                } catch (Throwable $e) {
                    // 忽略关闭通道时的异常
                }
                $this->mqChannel = null;
            }

            // 等待短暂时间后重建
            usleep(500000); // 0.5秒

            // 重新初始化MQ连接和队列
            $this->mqChannel = MQService::getChannel();
            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlxQueue);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlxQueue);

            // 重新订阅队列
            $this->mqChannel->basic_consume(
                $this->queueName,
                '',
                false,
                false,
                false,
                false,
                [$this, 'handleMessage']
            );

            Log::info('ImportProcess MQ连接重建成功');
        } catch (Throwable $e) {
            Log::error('ImportProcess MQ连接重建失败: ' . $e->getMessage());
            $this->mqChannel = null;
        }
    }
}
