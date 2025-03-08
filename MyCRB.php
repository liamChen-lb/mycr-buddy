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

        // 替换 ??= 为传统写法
        $this->config['context_length'] = $this->config['context_length'] ?? 4096;
        $this->config['log_dir'] = $this->config['log_dir'] ?? __DIR__ . '/logs';
    }

    private function initLogFile()
    {
        $filename      = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $this->prUrl);
        $date          = date('Y.m.d');
        $this->logFile = $this->config['log_dir'] . '/' . $filename . "+{$date}.log";
        $this->ensureDirExists(dirname($this->logFile));
    }

    private function ensureDirExists($dirPath)
    {
        if (!file_exists($dirPath)) {
            try {
                if (!mkdir($dirPath, 0750, true) && !is_dir($dirPath)) {
                    throw new Exception("目录创建失败: {$dirPath}");
                }
                $this->logInput("Created directory: {$dirPath}");
            } catch (Exception $e) {
                throw new Exception("目录创建错误: {$e->getMessage()}");
            }
        }

        if (!is_writable($dirPath)) {
            throw new Exception("目录不可写: {$dirPath}");
        }
    }

    public function run($prUrl)
    {
        try {
            $this->prUrl = $prUrl;
            $this->initLogFile();

            $this->logInput("Command: " . implode(' ', $_SERVER['argv']));
            $this->logInput("Starting review for PR: {$prUrl}");

            $diffContent = $this->getGitHubDiff();
            $this->logDiff($diffContent);

            $startTime = microtime(true);
            $this->callOllama($diffContent);
            $duration = round(microtime(true) - $startTime, 2);

            $this->logInput("Review completed in {$duration}s");
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
        $prompt = str_replace('{diff}', $diffContent, $this->config['prompt']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => rtrim($this->config['ollama_host'], '/') . '/api/generate',
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'   => $this->config['model_name'],
                'prompt'  => $prompt,
                'stream'  => true,
                'options' => ['num_ctx' => $this->config['context_length']]
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => $this->getStreamHandler(),
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_TCP_NODELAY    => true
        ]);

        if (!curl_exec($ch)) {
            throw new RuntimeException("Ollama连接失败: " . curl_error($ch));
        }
        curl_close($ch);
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
            $this->buffer .= $chunk;

            $this->logMessage('OLLAMA', $chunk);

            if ($response['done'] ?? false) {
                $timestamp = date('[Y-m-d H:i:s]');

                if (!empty($this->ollamaBuffer)) {
                    $logEntry = $this->isFirstOllamaLine
                        ? "{$timestamp} OLLAMA: {$this->ollamaBuffer}\n"
                        : "{$this->ollamaBuffer}\n";

                    $this->logBuffer    .= $logEntry;
                    $this->ollamaBuffer = '';
                }

                $this->logOutput($this->buffer);
                $this->buffer            = '';
                $this->isFirstOllamaLine = true;
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
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
}

// CLI入口
if (php_sapi_name() === 'cli') {
    // 调整版本检查为7.3
    if (PHP_VERSION_ID < 70300) {
        die("需要PHP 7.3或更高版本\n");
    }

    if (!isset($argv[1])) {
        die("使用方法: php " . basename(__FILE__) . " <PR_URL>\n");
    }

    try {
        $reviewer = new MyCRB();
        $reviewer->run($argv[1]);
    } catch (Throwable $e) {
        die("[致命错误] " . $e->getMessage() . PHP_EOL);
    }
}