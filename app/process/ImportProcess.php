<?php

namespace app\process;

use app\model\ImportJob;
use app\service\WordpressImporter;
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
        // 定时检查是否有待处理的导入任务
        $this->timerId = Timer::add($this->interval, [$this, 'checkImportJobs']);
        \support\Log::info("导入进程已启动，检查间隔: " . $this->interval . " 秒");
        
        // 检查是否有卡住的任务
        $this->checkStuckJobs();
    }

    /**
     * 检查是否有卡住的任务
     *
     * @return void
     */
    protected function checkStuckJobs(): void
    {
        // 查找长时间处于processing状态的任务（超过1小时）
        $stuckJobs = ImportJob::where('status', 'processing')
            ->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->get();
            
        foreach ($stuckJobs as $job) {
            \support\Log::warning("发现卡住的导入任务，重置状态为pending: ID=" . $job->id . ", 文件=" . $job->name);
            $job->update([
                'status' => 'pending',
                'message' => '任务被重置，之前可能已卡住'
            ]);
        }
    }

    /**
     * 检查导入任务
     *
     * @return void
     */
    public function checkImportJobs(): void
    {
        try {
            // 优先处理卡住的任务
            $processingJob = ImportJob::where('status', 'processing')
                ->orderBy('updated_at')
                ->first();
                
            // 如果有卡住的任务（超过5分钟未更新），重新处理
            if ($processingJob && strtotime($processingJob->updated_at) < time() - 300) {
                \support\Log::warning("发现可能卡住的处理中任务，重新处理: ID=" . $processingJob->id . ", 文件=" . $processingJob->name);
                $processingJob->update([
                    'status' => 'pending',
                    'message' => '任务被重置，重新处理'
                ]);
            }
            
            // 查找待处理的导入任务
            $job = ImportJob::where('status', 'pending')
                ->orderBy('created_at')
                ->first();

            if ($job) {
                \support\Log::info("找到待处理的导入任务: ID=" . $job->id . ", 文件=" . $job->name);
                
                $this->currentJob = $job;
                
                try {
                    // 根据任务类型选择处理器
                    switch ($job->type) {
                        case 'wordpress_xml':
                            \support\Log::info("开始处理WordPress XML导入任务: " . $job->name);
                            $importer = new WordpressImporter($job);
                            $result = $importer->execute();
                            \support\Log::info("WordPress XML导入任务处理完成: " . $job->name . ", 结果=" . ($result ? '成功' : '失败'));
                            break;
                        
                        default:
                            $errorMessage = '未知的导入类型: ' . $job->type;
                            \support\Log::error($errorMessage);
                            $job->update([
                                'status' => 'failed',
                                'message' => $errorMessage
                            ]);
                            break;
                    }
                } catch (\Exception $e) {
                    \support\Log::error('导入任务执行错误: ' . $e->getMessage(), ['exception' => $e]);
                    $job->update([
                        'status' => 'failed',
                        'message' => '导入任务执行错误: ' . $e->getMessage()
                    ]);
                }
                
                // 清除当前任务
                $this->currentJob = null;
                \support\Log::info("导入任务处理结束: " . $job->name);
            } else {
                // 只在需要时记录，避免日志过多
                static $lastLogged = 0;
                if (time() - $lastLogged > 300) { // 每300秒最多记录一次
                    \support\Log::debug("暂无待处理的导入任务");
                    $lastLogged = time();
                }
            }
        } catch (\Exception $e) {
            \support\Log::error('检查导入任务时出错: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}