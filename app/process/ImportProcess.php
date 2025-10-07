<?php

namespace app\process;

use app\model\ImportJob;
use app\service\WordpressImporter;
use app\service\MQService;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
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
     * 当前处理中的任务
     *
     * @var ImportJob|null
     */
    protected ?ImportJob $currentJob = null;

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
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        // 检查 .env 是否存在
        $config_file = base_path() . '/.env';
        if (!is_file($config_file)) {
            \support\Log::info("缺少 .env，跳过导入队列消费");
            return;
        }

        try {
            // 覆盖命名（可通过配置）
            $this->exchange    = (string)blog_config('rabbitmq_import_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey  = (string)blog_config('rabbitmq_import_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName   = (string)blog_config('rabbitmq_import_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string)blog_config('rabbitmq_import_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlxQueue    = (string)blog_config('rabbitmq_import_dlx_queue', $this->dlxQueue, true) ?: $this->dlxQueue;

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

            // 轮询消费
            if (class_exists(\Workerman\Timer::class)) {
                $this->timerId = Timer::add(1, function () {
                    try {
                        if ($this->mqChannel && $this->mqChannel->is_consuming()) {
                            $this->mqChannel->wait(null, false, 1.0);
                        }
                    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                        // 正常超时
                    } catch (\Throwable $e) {
                        Log::warning('ImportProcess 消费轮询异常: ' . $e->getMessage());
                    }
                });
            }

            \support\Log::info("ImportProcess 队列初始化成功");
        } catch (\Throwable $e) {
            \support\Log::error("ImportProcess 队列初始化失败: " . $e->getMessage());
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
                \support\Log::warning("发现卡住的导入任务，重置状态为pending: ID=" . $job->id . ", 文件=" . $job->name);
                $job->update([
                    'status' => 'pending',
                    'message' => '长时间处于processing状态，任务被重置'
                ]);
            }
        } catch (\Exception $e) {
            \support\Log::error('检查卡住的任务时出错: ' . $e->getMessage(), ['exception' => $e]);
        }
    }



    /**
     * 消费并处理导入消息
     */
    public function handleMessage(AMQPMessage $message): void
    {
        try {
            $data = json_decode($message->getBody(), true) ?: [];
            if (empty($data['type']) || empty($data['file_path']) || empty($data['name'])) {
                Log::warning('ImportProcess 收到无效消息: ' . $message->getBody());
                $message->ack();
                return;
            }

            // 创建 ImportJob 记录用于状态追踪
            $job = new ImportJob();
            $job->name = (string)$data['name'];
            $job->type = (string)$data['type'];
            $job->file_path = (string)$data['file_path'];
            $job->author_id = isset($data['author_id']) ? (int)$data['author_id'] : null;
            $job->options = json_encode($data['options'] ?? [], JSON_UNESCAPED_UNICODE);
            $job->status = 'processing';
            $job->progress = 0;
            $job->message = '队列消费开始';
            $job->save();

            $this->currentJob = $job;

            // 执行导入
            switch ($job->type) {
                case 'wordpress_xml':
                    Log::info("开始处理WordPress XML导入任务: " . $job->name);
                    $importer = new WordpressImporter($job);
                    $result = $importer->execute();
                    $job->update([
                        'status' => $result ? 'completed' : 'failed',
                        'progress' => 100,
                        'message' => $result ? '导入完成' : '导入失败'
                    ]);
                    Log::info("WordPress XML导入任务处理完成: " . $job->name);
                    break;
                default:
                    $errorMessage = '未知的导入类型: ' . $job->type;
                    Log::error($errorMessage);
                    $job->update([
                        'status' => 'failed',
                        'message' => $errorMessage
                    ]);
                    break;
            }

            $this->currentJob = null;
            $message->ack();
        } catch (\Throwable $e) {
            Log::error('导入任务执行错误: ' . $e->getMessage());
            // 简易处理：进入 DLX（队列已配置DLX），避免阻塞
            $message->nack(false);
        }
    }

    /**
     * 进程停止时执行
     *
     * @param Worker $worker
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
}