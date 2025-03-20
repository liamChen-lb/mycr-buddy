<?php

namespace MyCRB;

use Exception;
use MyCRB\Handlers\LogHandler;
use MyCRB\Services\BatchManager;
use MyCRB\Services\DiffSplitter;
use MyCRB\Services\GitHubService;
use MyCRB\Services\OllamaService;
use RuntimeException;

class MyCRB
{
    private array $config;
    private string $prUrl;
    private string $finalReviewContent = '';
    private string $batchReviewContent = '';
    private GitHubService $githubService;
    private OllamaService $ollamaService;
    private LogHandler $logHandler;

    private string $buffer = '';

    private string $ollamaBuffer = '';
    private string $logBuffer = '';
    private bool $isFirstOllamaLine = true;
    private string $logFile;

    // 构造函数初始化配置和输出缓冲
    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/code_review.php';
        $this->validateConfig();
        $this->githubService = new GitHubService($this->config);
        $this->ollamaService = new OllamaService($this->config);
        ob_start();
    }

    // 验证配置完整性
    private function validateConfig(): void
    {
        $requiredKeys = ['github_token', 'ollama_host', 'model_name'];
        foreach ($requiredKeys as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException("缺少必要配置项: {$key}");
            }
        }

        if (!filter_var($this->config['ollama_host'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException("无效的Ollama地址: {$this->config['ollama_host']}");
        }

        // 设置默认配置值
        $this->config['context_length'] = $this->config['context_length'] ?? 4096;
        $this->config['log_dir']        = $this->config['log_dir'] ?? __DIR__ . '/logs';

        // 添加分词器配置，确保有预设的安全回退选项
        $this->config['tokenizer_model']    = $this->config['tokenizer_model'] ?? 'gpt-3.5-turbo-0301';
        $this->config['tokenizer_fallback'] = $this->config['tokenizer_fallback'] ?? 'gpt-3.5-turbo-0301';
    }


    /**
     * 初始化日志文件路径
     * @throws Exception
     */
    private function initLogFile(): void
    {
        $safeUrl   = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $this->prUrl);
        $timestamp = date('Y.m.d.H.i.s');

        // 确保日志目录存在
        $this->ensureDirExists($this->config['log_dir']);

        // 生成完整日志文件路径
        $this->logFile = sprintf(
            '%s/%s+%s.log',
            rtrim($this->config['log_dir'], '/'),
            $safeUrl,
            $timestamp
        );

        // 二次验证文件路径格式
        if (is_dir($this->logFile)) {
            throw new RuntimeException("生成的日志路径是目录: {$this->logFile}");
        }
    }

    /**
     * 确保日志目录存在且可写
     * @param string $dirPath
     * @return void
     * @throws Exception
     */
    private function ensureDirExists(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0750, true)) {
                throw new Exception("目录创建失败: {$dirPath}");
            }
        }
        if (!is_writable($dirPath)) {
            throw new Exception("目录不可写: {$dirPath}");
        }
    }


    /**
     * 主执行流程
     * @throws Exception
     */
    public function run($prUrl, $postType = null): void
    {
        set_time_limit(600);
        $this->prUrl = $prUrl;
        $this->initLogFile();
        $this->logHandler = new LogHandler($this->logFile);
        $this->logInput("Command: " . implode(' ', $_SERVER['argv']));
        $this->logInput("Starting review for PR: {$prUrl}");

        try {
            $startTime = microtime(true);

            $diffContent = $this->getGitHubDiff();
            // 记录完整的diff code
            $this->logHandler->logFullDiff($diffContent, $this->ollamaService->countTokens($diffContent));

            if ($postType === 'now') {
                $this->executeAndSubmitReview($diffContent);
            } elseif ($postType === 'pre') {
                $this->handlePreviousReview($diffContent);
            } else {
                $this->generateReview($diffContent);
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->logInput("Review completed in {$duration}s");
            // 需要刷新日志缓冲并关闭日志文件
            $this->logHandler->flushLog();
        } catch (Exception $e) {
            $this->handleError($e);
        } finally {
            ob_end_flush();
        }
    }

    private function createChunksWithTokenCount(array $fileDiffs): array
    {
        return array_map(function ($diff) {
            return [
                'file'        => $this->extractFileName($diff),
                'content'     => $diff,
                'token_count' => $this->ollamaService->countTokens($diff)
            ];
        }, $fileDiffs);
    }

    private function extractFileName($diff): string
    {
        $lines = explode("\n", $diff);
        if (count($lines) > 1) {
            return trim($lines[1]);
        }
        return '';
    }

    // 处理历史评审提交
    private function handlePreviousReview($diffContent): void
    {
        if (!$this->submitPreviousReview()) {
            $this->generateReview($diffContent);
            echo "⚠️ 未找到历史评审记录，已生成新评审内容但未提交。使用 -p pre 提交本次结果；或使用 -p now 重新生成并提交" . PHP_EOL;
        }
    }

    // 执行评审生成并提交
    private function executeAndSubmitReview($diffContent): void
    {
        $this->generateReview($diffContent);
        $this->submitCurrentReview();
    }

    // 仅生成评审不提交
    private function generateReview($diffContent): void
    {
        // 引入DiffSplitter类
        $splitter  = new DiffSplitter();
        $fileDiffs = $splitter->splitByFile($diffContent);

        // 创建代码块并计算token
        $chunks = $this->createChunksWithTokenCount($fileDiffs);

        // 生成批次
        $batchManager = new BatchManager($this->config['context_length']);
        $batches      = $batchManager->createBatches(
            $chunks,
            $this->ollamaService->countTokens($this->config['prompt'])
        );

        // 处理分批评审
        $partialResults = $this->processBatchedReview($batches);

        // 聚合最终结果
        // 当只有一批的时候，就不需要聚合，直接返回即可
        if (count($batches) > 1) {
            $summaryPrompt = $this->ollamaService->generateSummaryReview(
                $partialResults,
                $this->getStreamHandler()
            );
            $this->logHandler->logMessage(LogHandler::LOGOUT_FLAG_SUMMARY_PROMPT, $summaryPrompt);
        }
        // 会将最后的聚合评审结果也放在 batchReviewContent，因此直接赋值
        $this->finalReviewContent = $this->batchReviewContent;
        $this->logHandler->logFinalReview($this->finalReviewContent, $this->config['model_name']);
    }

    // 获取GitHub PR的diff内容
    private function getGitHubDiff(): string
    {
        return $this->githubService->getDiff($this->prUrl);
    }


    // 获取流式响应处理回调
    private function getStreamHandler(): \Closure
    {
        return function ($ch, $data) {
            $response = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->handleStreamResponse($response);
            }
            return strlen($data);
        };
    }

    // 处理Ollama流式响应
    private function handleStreamResponse(array $response): void
    {
        if (!isset($response['response'])) {
            return;
        }

        $chunk = $response['response'];
        echo $chunk; // 实时输出到终端
        $this->buffer             .= $chunk;
        $this->batchReviewContent = $this->buffer; // 实时更新最终内容

        $this->logMessage(LogHandler::LOGOUT_FLAG_OLLAMA, $chunk);

        // 强制立即刷新输出缓冲区
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        if (!empty($response['done'])) {
            $this->logInput("Ollama生成完成，内容长度: " . strlen($this->buffer));
            $this->buffer            = '';
            $this->isFirstOllamaLine = true;
        }
    }

    // 日志记录方法
    private function logMessage($type, $message): void
    {
        $this->logHandler->logMessage($type, $message);
    }


    // 提交当前评审到GitHub
    private function submitCurrentReview(): void
    {
        if (empty(trim($this->finalReviewContent))) {
            throw new RuntimeException("没有可用的评审内容");
        }

        try {
            // 这里也需要拼接上模型名称
            $content = "## " . htmlspecialchars($this->config['model_name']) . "\n\n" . $this->finalReviewContent;
            $this->postGitHubComment($content);
            $this->logInput("已成功提交评审到PR评论");
        } catch (Exception $e) {
            $this->logInput("提交评审失败: " . $e->getMessage());
            throw $e;
        }
    }

    // 发送GitHub评论
    private function postGitHubComment($commentBody): void
    {
        $parsed    = parse_url($this->prUrl);
        $pathParts = explode('/', trim($parsed['path'] ?? '', '/'));

        // 记录调试信息
        $this->logInput(
            sprintf(
                '正在提交评论到：owner=%s, repo=%s, pr=%s',
                $pathParts[0],
                $pathParts[1],
                $pathParts[3]
            )
        );

        try {
            // 直接使用评审内容，因为模型名称已经在日志记录时添加
            $this->githubService->postComment(
                $pathParts[0], // owner
                $pathParts[1], // repo
                (int)$pathParts[3], // prNumber转换为整数
                $commentBody
            );
        } catch (\RuntimeException $e) {
            $this->logInput('GitHub API错误详情：' . $e->getMessage());
            throw $e;
        }
    }

    // 提交历史评审
    public function submitPreviousReview(): bool
    {
        $logDir     = $this->config['log_dir'];
        $prFilename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $this->prUrl);
        $pattern    = '/^' . preg_quote($prFilename, '/') . '\+\d{4}\.\d{2}\.\d{2}\.\d{2}\.\d{2}\.\d{2}\.log$/';
        $files      = array_filter(scandir($logDir), function ($file) use ($pattern) {
            return preg_match($pattern, $file);
        });

        if (empty($files)) {
            $this->logInput("未找到任何历史评审记录");
            return false;
        }

        // 优化后的排序逻辑
        usort($files, function ($a, $b) use ($logDir) {
            return filemtime("{$logDir}/$b") <=> filemtime("{$logDir}/$a"); // 太空船操作符简化比较
        });

        // 新增强制类型转换和过滤逻辑
        $files = array_values(array_diff($files, [basename($this->logFile)])); // 过滤当前日志并重置索引

        if (empty($files)) {
            $this->logInput("无可用历史评审");
            return false;
        }

        $previousFile = $files[0]; // 直接取排序后首个文件

        if (!$previousFile) {
            $this->logInput("未找到可用的历史评审");
            return false;
        }

        $logContent = file_get_contents("{$logDir}/$previousFile");

        // 修复正则表达式，匹配完整的日志行格式
        $pattern = '/' . preg_quote(LogHandler::LOGOUT_FLAG_FINAL_REVIEW) . ': (.*?)(?=\n\[|\Z)/s';

        preg_match_all($pattern, $logContent, $matches);
        $reviewContent = trim(implode("\n", $matches[1]));

        if (empty($reviewContent)) {
            $this->logInput("历史评审内容为空");
            return false;
        }
        // 将历史评审也写入到日志中
        $this->logHandler->logFinalReview($reviewContent);

        $this->postGitHubComment($reviewContent);
        $this->logInput("历史评审已成功提交到PR评论");
        return true;
    }

    // 析构函数确保日志写入
    public function __destruct()
    {
        if (!empty($this->ollamaBuffer)) {
            $timestamp = date('[Y-m-d H:i:s]');
            $padding   = $this->isFirstOllamaLine ? '' : str_repeat(' ', strlen($timestamp) + 11);
            $logEntry  = $this->isFirstOllamaLine
                ? "{$timestamp} OLLAMA: {$this->ollamaBuffer}\n"
                : "{$padding}{$this->ollamaBuffer}\n";

            $this->logBuffer    .= $logEntry;
            $this->ollamaBuffer = '';
        }

        $this->logHandler->flushLog();
    }


    // 错误处理
    private function handleError(Exception $e): void
    {
        $message = "Error [{$e->getCode()}]: {$e->getMessage()}";
        echo $message . PHP_EOL;
        $this->logInput($message);
    }

    // 记录用户输入日志
    private function logInput($message): void
    {
        $this->logMessage(LogHandler::LOGOUT_FLAG_USER, $message);
    }


    private function processBatchedReview(array $batches): array
    {
        $partialResults = [];
        foreach ($batches as $index => $batch) {
            // 记录当前批次diff
            $this->logHandler->logBatchStart($batch['id'], count($batch['chunks']));
            $this->logHandler->logBatchDiff($batch['id'], implode("\n", array_column($batch['chunks'], 'content')));
            $this->logHandler->logBatchMeta($batch);

            $this->logHandler->logMessage(LogHandler::LOGOUT_FLAG_PROMPT, $this->config['prompt']);
            $this->ollamaService->generateBatchReview(
                $batch['chunks'],
                $this->getStreamHandler()
            );
            $partialResults[$batch['id']] = $this->batchReviewContent;
        }
        return $partialResults;
    }


}