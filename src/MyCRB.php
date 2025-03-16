<?php

namespace MyCRB;

use Exception;
use MyCRB\Handlers\LogHandler;
use MyCRB\Services\BatchManager;
use MyCRB\Services\DiffSplitter;
use MyCRB\Services\GitHubService;
use MyCRB\Services\OllamaService;
use RuntimeException;
use Yethee\Tiktoken\EncoderProvider;

class MyCRB
{


    private $config;
    private $prUrl;
    private $finalReviewContent = '';
    private $batchReviewContent = '';
    private GitHubService $githubService;
    private OllamaService $ollamaService;
    private LogHandler $logHandler;

    private $buffer = '';

    private $ollamaBuffer = '';
    private $logBuffer = '';
    private $isFirstOllamaLine = true;
    private $logFile;

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
    private function validateConfig()
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
    }

    // 初始化日志文件路径
    private function initLogFile()
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

    // 确保日志目录存在且可写
    private function ensureDirExists($dirPath)
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

    // 主执行流程
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
            $this->logHandler->logFullDiff($diffContent);

            if ($postType === 'now') {
                $this->executeAndSubmitReview($diffContent);
            } elseif ($postType === 'pre') {
                $this->handlePreviousReview($diffContent);
            } else {
                $this->generateReview($diffContent);
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->logInput("Review completed in {$duration}s");
        } catch (Exception $e) {
            $this->handleError($e);
        } finally {
            ob_end_flush();
        }
    }

    private function createChunksWithTokenCount(array $fileDiffs): array
    {
        $provider = new EncoderProvider();
        $encoder  = $provider->getForModel('gpt-4');

        return array_map(function ($diff) use ($encoder) {
            return [
                'file'        => $this->extractFileName($diff),
                'content'     => $diff,
                'token_count' => count($encoder->encode($diff))
            ];
        }, $fileDiffs);
    }

    // extractFileName
    private function extractFileName($diff): string
    {
        $lines = explode("\n", $diff);
        if (count($lines) > 1) {
            return trim($lines[1]);
        }
        return '';
    }

    // 处理历史评审提交
    private function handlePreviousReview($diffContent)
    {
        if (!$this->submitPreviousReview()) {
            $this->generateReview($diffContent);
            echo "⚠️ 未找到历史评审记录，已生成新评审内容但未提交。使用 -p pre 提交本次结果；或使用 -p now 重新生成并提交" . PHP_EOL;
        }
    }

    // 执行评审生成并提交
    private function executeAndSubmitReview($diffContent)
    {
        $this->generateReview($diffContent);
        $this->submitCurrentReview();
    }

    // 仅生成评审不提交
    private function generateReview($diffContent)
    {
        // 引入DiffSplitter类
        $splitter  = new DiffSplitter();
        $fileDiffs = $splitter->splitByFile($diffContent);

        // 创建代码块并计算token
        $chunks = $this->createChunksWithTokenCount($fileDiffs);

        // 生成批次
        $batchManager = new BatchManager($this->config['context_length']);
        $batches      = $batchManager->createBatches($chunks);

        // 处理分批评审
        $partialResults = $this->processBatchedReview($batches);

        // 聚合最终结果
        $summaryPrompt = $this->ollamaService->generateSummaryReview(
            $partialResults,
            $this->getStreamHandler()
        );
        $this->logHandler->logMessage('[SUMMARY_PROMPT]', $summaryPrompt);
        // 会将最后的聚合评审结果也放在 batchReviewContent，因此直接赋值
        $this->finalReviewContent = $this->batchReviewContent;
        $this->logHandler->logFinalReview($this->finalReviewContent);
    }

    // 获取GitHub PR的diff内容
    private function getGitHubDiff()
    {
        return $this->githubService->getDiff($this->prUrl);
    }

    // 从响应头解析HTTP状态码
    private function getStatusCodeFromHeader($headers)
    {
        foreach ($headers as $header) {
            if (strpos($header, 'HTTP/') === 0) {
                return (int)substr($header, 9, 3);
            }
        }
        return 0;
    }

    // 调用Ollama生成评审内容
    // 修改callOllama方法来动态设置上下文
    /**
     * todo：该方法或许可以弃用
     * @param $diffContent
     * @return void
     */
    private function callOllama($diffContent)
    {
        // 记录完整diff
//        $this->logHandler->logFullDiff($diffContent);
        $diffTokenCount   = $this->ollamaService->countTokens($diffContent);
        $promptTokenCount = $this->ollamaService->countTokens($this->config['prompt']);
        // 这里还需要考虑prompt的长度
        $tokenCount = $diffTokenCount + $promptTokenCount;
        if ($tokenCount > $this->config['context_length']) {
            throw new RuntimeException(
                "代码+Prompt的token数量超过了配置中的最大上下文长度, 代码：$tokenCount ，prompt：$promptTokenCount"
            );
        }
        $this->logHandler->logMessage('PROMPT', $this->config['prompt']);
        $this->ollamaService->generateReview(
            $diffContent,
            $this->getStreamHandler()
        );
    }

    // 获取流式响应处理回调
    private function getStreamHandler()
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
    private function handleStreamResponse(array $response)
    {
        if (!isset($response['response'])) {
            return;
        }

        $chunk = $response['response'];
        echo $chunk; // 实时输出到终端
        $this->buffer             .= $chunk;
        $this->batchReviewContent = $this->buffer; // 实时更新最终内容

        $this->logMessage('OLLAMA', $chunk);

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
    private function logMessage($type, $message)
    {
        $this->logHandler->logMessage($type, $message);
    }

    // 格式化日志消息

    // 提交当前评审到GitHub
    private function submitCurrentReview()
    {
        if (empty(trim($this->finalReviewContent))) {
            throw new RuntimeException("没有可用的评审内容");
        }

        try {
            $this->postGitHubComment($this->finalReviewContent);
            $this->logInput("已成功提交评审到PR评论");
        } catch (Exception $e) {
            $this->logInput("提交评审失败: " . $e->getMessage());
            throw $e;
        }
    }

    // 发送GitHub评论
    private function postGitHubComment($commentBody)
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
    public function submitPreviousReview()
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

        usort($files, function ($a, $b) use ($logDir) {
            return filemtime("{$logDir}/$b") - filemtime("{$logDir}/$a");
        });

        $currentLog   = basename($this->logFile);
        $previousFile = null;

        // TODO: 这里的排序获取上一次评审文件的方式，有问题，待优化
        foreach ($files as $file) {
            if ($file != $currentLog) {
                $previousFile = $file;
                break;
            }
        }

        if (!$previousFile) {
            $this->logInput("未找到可用的历史评审");
            return false;
        }

        $logContent = file_get_contents("{$logDir}/$previousFile");
        // 当前的final评审格式为： [2025-03-16 09:31:02] [FINAL_REVIEW]: ## 综合评估与建议
        preg_match_all('/\[FINAL_REVIEW\]: (.*?)(?=\n\[|\Z)/s', $logContent, $matches);
        $reviewContent = trim(implode("\n", $matches[1]));

        if (empty($reviewContent)) {
            $this->logInput("历史评审内容为空");
            return false;
        }

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

    // 刷新日志缓冲区
    private function flushLogBuffer()
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $retry = 0;
        while ($retry < 3) {
            $bytes = @file_put_contents($this->logFile, $this->logBuffer, FILE_APPEND);
            if ($bytes !== false) {
                chmod($this->logFile, 0640);
                $this->logBuffer = '';
                return;
            }
            usleep(100000);
            $retry++;
        }

        error_log("日志写入失败: " . $this->logFile);
    }

    // 错误处理
    private function handleError(Exception $e)
    {
        $message = "Error [{$e->getCode()}]: {$e->getMessage()}";
        echo $message . PHP_EOL;
        $this->logInput($message);
    }

    // 记录用户输入日志
    private function logInput($message)
    {
        $this->logMessage(LogHandler::LOGOUT_FLAG_USER, $message);
    }

    // 记录diff内容日志
    private function logDiff($content)
    {
        $tokenCount = $this->ollamaService->countTokens($content);
        $this->logMessage('DIFF', "PR Diff Content:\n" . $content . "\nToken Count: " . $tokenCount);
    }

    // 记录系统输出日志
    private function logOutput($message)
    {
        $this->logMessage('ASSISTANT', $message);
    }

    private function processBatchedReview(array $batches): array
    {
        $partialResults = [];
        foreach ($batches as $index => $batch) {
            // 记录当前批次diff
            $this->logHandler->logBatchStart($batch['id'], count($batch['chunks']));
            $this->logHandler->logBatchDiff($batch['id'], implode("\n", array_column($batch['chunks'], 'content')));
            $this->logHandler->logBatchMeta($batch);

            $this->logHandler->logMessage('[PROMPT]', $this->config['prompt']);
            $this->ollamaService->generateBatchReview(
                $batch['chunks'],
                $this->getStreamHandler()
            );
            $partialResults[$batch['id']] = $this->batchReviewContent;
        }
        return $partialResults;
    }


}