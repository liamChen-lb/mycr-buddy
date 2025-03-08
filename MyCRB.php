<?php

class MyCRB
{
    private $config;
    private $logFile;
    private $prUrl;
    private $buffer = '';
    private $logBuffer = '';
    private const LOG_FLUSH_LIMIT = 4096;
    private $ollamaBuffer = '';

    private $isFirstOllamaLine = true;
    private $finalReviewContent = ''; // 新增最终评审内容存储


    public function __construct()
    {
        $this->config = require __DIR__ . '/config/code_review.php';
        $this->validateConfig();
        ob_start();
    }

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

        $this->config['context_length'] = $this->config['context_length'] ?? 4096;
        $this->config['log_dir']        = $this->config['log_dir'] ?? __DIR__ . '/logs';
    }

    private function initLogFile()
    {
        $filename      = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $this->prUrl);
        $timestamp     = date('Y.m.d.H.i.s');
        $this->logFile = $this->config['log_dir'] . '/' . $filename . "+{$timestamp}.log";
        $this->ensureDirExists(dirname($this->logFile));
    }

    private function ensureDirExists($dirPath)
    {
        if (!file_exists($dirPath)) {
            if (!mkdir($dirPath, 0750, true) && !is_dir($dirPath)) {
                throw new Exception("目录创建失败: {$dirPath}");
            }
        }

        if (!is_writable($dirPath)) {
            throw new Exception("目录不可写: {$dirPath}");
        }
    }

    public function run($prUrl, $postType = null)
    {
        set_time_limit(600); // 设置全局超时时间

        try {
            $this->prUrl = $prUrl;
            $this->initLogFile();
            $this->logInput("Command: " . implode(' ', $_SERVER['argv']));
            $this->logInput("Starting review for PR: {$prUrl}");

            $diffContent = $this->getGitHubDiff();
            $this->logDiff($diffContent);

            if ($postType === 'now') {
                $startTime = microtime(true);
                $this->callOllama($diffContent);
                $duration = round(microtime(true) - $startTime, 2);
                $this->logInput("Review completed in {$duration}s");
                $this->submitCurrentReview();
            } elseif ($postType === 'pre') {
                $success = $this->submitPreviousReview();
                if (!$success) {
                    $startTime = microtime(true);
                    $this->callOllama($diffContent);
                    $duration = round(microtime(true) - $startTime, 2);
                    $this->logInput("Review completed in {$duration}s");
                    echo "⚠️ 未找到历史评审记录，已生成新评审内容但未提交。使用 -p pre 参数提交本次结果；或是使用-p now重新生成评审并提交\n";
                }
            } else {
                $startTime = microtime(true);
                $this->callOllama($diffContent);
                $duration = round(microtime(true) - $startTime, 2);
                $this->logInput("Review completed in {$duration}s");
            }
        } catch (Exception $e) {
            $this->handleError($e);
        } finally {
            ob_end_flush();
        }
    }

    private function handleError(Exception $e)
    {
        $message = "Error [{$e->getCode()}]: {$e->getMessage()}";
        echo $message . PHP_EOL;
        $this->logInput($message);
    }

    private function getGitHubDiff()
    {
        $parsed    = parse_url($this->prUrl);
        $pathParts = explode('/', trim($parsed['path'] ?? '', '/'));

        if (count($pathParts) < 4 || $pathParts[2] !== 'pull') {
            throw new InvalidArgumentException("无效的PR URL格式");
        }

        list($owner, $repo, , $prNumber) = $pathParts;

        $apiUrl  = "https://api.github.com/repos/{$owner}/{$repo}/pulls/{$prNumber}";
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => [
                    "User-Agent: PHP-PR-Reviewer/1.0",
                    "Authorization: Bearer {$this->config['github_token']}",
                    "Accept: application/vnd.github.v3.diff"
                ],
                'ignore_errors' => true
            ]
        ]);

        $response = file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException("GitHub API请求失败: {$error['message']}");
        }

        $statusCode = explode(' ', $http_response_header[0] ?? '')[1] ?? 'unknown';
        if ($statusCode != 200) {
            throw new RuntimeException("GitHub API返回错误: HTTP {$statusCode} - " . substr($response, 0, 512));
        }

        return $response;
    }


    private function callOllama($diffContent)
    {
        $prompt       = str_replace('{diff}', $diffContent, $this->config['prompt']);
        $modelOptions = array_merge([
            'temperature'    => 0.1,
            'top_p'          => 0.9,
            'repeat_penalty' => 1.1
        ], $this->config['model_params'] ?? []);

        $maxRetries = 3;
        $retryCount = 0;
        $success    = false;

        while ($retryCount < $maxRetries && !$success) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => rtrim($this->config['ollama_host'], '/') . '/api/generate',
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode([
                    'model'   => $this->config['model_name'],
                    'prompt'  => $prompt,
                    'stream'  => true,
                    'options' => array_merge([
                        'num_ctx' => $this->config['context_length']
                    ], $modelOptions)
                ]),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION  => $this->getStreamHandler(),
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_TCP_NODELAY    => true
            ]);

            if (curl_exec($ch)) {
                $success = true;
            } else {
                $retryCount++;
                $this->logInput("Ollama调用失败，重试次数：{$retryCount}/{$maxRetries}");
                sleep(1);
            }
            curl_close($ch);
        }

        if (!$success) {
            throw new RuntimeException("Ollama服务调用失败，已达到最大重试次数");
        }
    }

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

    private function logDiff($content)
    {
        $this->logMessage('DIFF', "PR Diff Content:\n" . $content);
    }

    private function logInput($message)
    {
        $this->logMessage('USER', $message);
    }

    private function logOutput($message)
    {
        $this->logMessage('ASSISTANT', $message);
    }

    private function logMessage($type, $message)
    {
        $timestamp = date('[Y-m-d H:i:s]');
        $logEntry  = '';

        if ($type === 'OLLAMA') {
            $message            = str_replace(["\r\n", "\r"], "\n", $message);
            $this->ollamaBuffer .= $message;

            $lines              = explode("\n", $this->ollamaBuffer);
            $this->ollamaBuffer = array_pop($lines);

            foreach ($lines as $line) {
                if ($this->isFirstOllamaLine) {
                    $logEntry                .= "{$timestamp} OLLAMA: {$line}\n";
                    $this->isFirstOllamaLine = false;
                } else {
                    $logEntry .= "{$line}\n";
                }
            }
        } else {
            $logEntry = "{$timestamp} {$type}: {$message}\n";
        }

        $this->logBuffer .= $logEntry;

        if (strlen($this->logBuffer) >= self::LOG_FLUSH_LIMIT / 2) {
            $this->flushLogBuffer();
        }
    }


    private function handleStreamResponse(array $response)
    {
        if (isset($response['response'])) {
            $chunk = $response['response'];
            echo $chunk;
            $this->buffer             .= $chunk;
            $this->finalReviewContent = $this->buffer; // 实时更新最终内容

            $this->logMessage('OLLAMA', $chunk);

            if (isset($response['done']) && $response['done']) {
                $this->logInput("Ollama生成完成，内容长度: " . strlen($this->buffer));
                $this->buffer            = '';
                $this->isFirstOllamaLine = true;
            }
        }
    }

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

        $this->flushLogBuffer();
    }

    private function flushLogBuffer()
    {
        if (!empty($this->logBuffer)) {
            $retry = 0;
            while ($retry < 3) {
                $bytes = @file_put_contents($this->logFile, $this->logBuffer, FILE_APPEND);
                if ($bytes !== false) {
                    break;
                }
                usleep(100000);
                $retry++;
            }

            if ($bytes === false) {
                error_log("日志写入失败: " . $this->logFile);
            } else {
                chmod($this->logFile, 0640);
                $this->logBuffer = '';
            }
        }
    }

    private function postGitHubComment($commentBody)
    {
        $parsed    = parse_url($this->prUrl);
        $pathParts = explode('/', trim($parsed['path'] ?? '', '/'));

        $owner    = $pathParts[0];
        $repo     = $pathParts[1];
        $prNumber = $pathParts[3];

        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$prNumber}/comments";

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => [
                    "User-Agent: PHP-PR-Reviewer/1.0",
                    "Authorization: Bearer {$this->config['github_token']}",
                    "Accept: application/vnd.github.v3+json",
                    "Content-Type: application/json"
                ],
                'content'       => json_encode(['body' => $commentBody]),
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException("评论提交失败: {$error['message']}");
        }

        $statusCode = explode(' ', $http_response_header[0] ?? '')[1] ?? 0;
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException("GitHub API返回错误: HTTP {$statusCode} - " . substr($response, 0, 512));
        }

        return json_decode($response, true);
    }


    public function submitCurrentReview()
    {
        if (empty(trim($this->finalReviewContent))) {
            $this->logInput("警告：尝试提交空评审内容");
            throw new RuntimeException("没有可用的评审内容，请检查Ollama服务状态或日志");
        }

        try {
            $this->postGitHubComment($this->finalReviewContent);
            $this->logInput("已成功提交评审到PR评论");
        } catch (Exception $e) {
            $this->logInput("提交评审失败: " . $e->getMessage());
            throw $e;
        }
    }

    public function submitPreviousReview()
    {
        $logDir     = $this->config['log_dir'];
        $prFilename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $this->prUrl);
        $pattern    = '/^' . preg_quote($prFilename, '/') . '\+\d{4}\.\d{2}\.\d{2}\.\d{2}\.\d{2}\.\d{2}\.log$/';

        $files = [];
        foreach (scandir($logDir) as $file) {
            if (preg_match($pattern, $file)) {
                $files[] = $file;
            }
        }

        if (empty($files)) {
            $this->logInput("未找到任何历史评审记录");
            return false;
        }

        usort($files, function ($a, $b) use ($logDir) {
            return filemtime("{$logDir}/$b") - filemtime("{$logDir}/$a");
        });

        $currentLog   = basename($this->logFile);
        $previousFile = null;
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
        preg_match_all('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] OLLAMA: (.*?)(?=\n\[|\Z)/s', $logContent, $matches);
        $reviewContent = trim(implode("\n", $matches[1]));

        if (empty($reviewContent)) {
            $this->logInput("历史评审内容为空");
            return false;
        }

        $this->postGitHubComment($reviewContent);
        $this->logInput("历史评审已成功提交到PR评论");
        return true;
    }
}

// CLI入口
if (php_sapi_name() === 'cli') {
    if (PHP_VERSION_ID < 70300) {
        die("需要PHP 7.3或更高版本\n");
    }

    $prUrl    = null;
    $postType = null;

    // 解析命令行参数
    $args = $_SERVER['argv'];
    array_shift($args); // 移除脚本名称

    foreach ($args as $i => $arg) {
        if ($arg === '-p' && isset($args[$i + 1])) {
            $postType = $args[$i + 1];
            unset($args[$i], $args[$i + 1]);
            break;
        }
    }

    if (empty($args)) {
        die("使用方法: php " . basename(__FILE__) . " <PR_URL> [-p now|pre]\n");
    }

    $prUrl = array_shift($args);

    try {
        $reviewer = new MyCRB();
        $reviewer->run($prUrl, $postType);
    } catch (Throwable $e) {
        die("[致命错误] " . $e->getMessage() . PHP_EOL);
    }
}