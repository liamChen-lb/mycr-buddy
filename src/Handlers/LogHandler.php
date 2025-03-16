<?php

namespace MyCRB\Handlers;

class LogHandler
{

    // 添加几个输出的常量，如[USER]等
    const LOGOUT_FLAG_USER         = '[USER]';
    const LOGOUT_FLAG_OLLAMA       = '[OLLAMA]';
    const LOGOUT_FLAG_FINAL_REVIEW = '[FINAL_REVIEW]';
    const LOGOUT_FLAG_BATCH_START  = '[BATCH START]';
    const LOGOUT_FLAG_BATCH_DIFF   = '[BATCH DIFF START]';
    const LOGOUT_FLAG_BATCH_END    = '[BATCH END]';
    const LOGOUT_FLAG_BATCH_INFO   = '[BATCH_INFO]';

    private $logFile;
    private $logBuffer = '';
    private const LOG_FLUSH_LIMIT = 4096;
    private $ollamaBuffer = '';
    private $isFirstOllamaLine = true;

    public function __construct(string $logFile)
    {
        if (is_dir($logFile)) {
            throw new \RuntimeException("日志路径指向目录而非文件: {$logFile}");
        }
        $this->logFile = $logFile;
        $this->ensureDirExists(dirname($this->logFile));
    }

    private function ensureDirExists($dirPath)
    {
        if (!is_dir($dirPath) && !mkdir($dirPath, 0750, true)) {
            throw new \RuntimeException("目录创建失败: {$dirPath}");
        }
        if (!is_writable($dirPath)) {
            throw new \RuntimeException("目录不可写: {$dirPath}");
        }
    }

    public function logMessage(string $type, string $message)
    {
        $formatted       = $this->formatMessage($type, $message);
        $this->logBuffer .= $formatted;

        if (strlen($this->logBuffer) >= self::LOG_FLUSH_LIMIT) {
            $this->flushLog();
        }
    }

    private function formatMessage(string $type, string $message): string
    {
        $timestamp = date('[Y-m-d H:i:s]');

        if ($type !== 'OLLAMA') {
            return "{$timestamp} {$type}: {$message}\n";
        }

        $this->ollamaBuffer .= str_replace(["\r\n", "\r"], "\n", $message);
        $lines              = explode("\n", $this->ollamaBuffer);
        $this->ollamaBuffer = array_pop($lines);

        $output = '';
        foreach ($lines as $line) {
            if ($this->isFirstOllamaLine) {
                $output                  .= "{$timestamp} OLLAMA: {$line}\n";
                $this->isFirstOllamaLine = false;
            } else {
                $output .= "{$line}\n";
            }
        }
        return $output;
    }

    public function flushLog()
    {
        try {
            if (!empty($this->logBuffer)) {
                if (is_dir($this->logFile)) {
                    throw new \RuntimeException("日志路径配置错误: {$this->logFile} 是目录");
                }
                $fp = fopen($this->logFile, 'a');
                if (!$fp) {
                    throw new \RuntimeException("无法打开日志文件: {$this->logFile}");
                }

                $retries = 0;
                while ($retries < 3) {
                    if (flock($fp, LOCK_EX)) {
                        fwrite($fp, $this->logBuffer);
                        flock($fp, LOCK_UN);
                        $this->logBuffer = '';
                        break;
                    }
                    usleep(100000); // 100ms重试间隔
                    $retries++;
                }
                fclose($fp);
            }
        } catch (\Exception $e) {
            error_log('日志写入失败: ' . $e->getMessage());
        }
    }

    public function __destruct()
    {
        if (!empty($this->ollamaBuffer)) {
            $this->logMessage('OLLAMA', $this->ollamaBuffer);
        }
        $this->flushLog();
    }

    public function logBatchMeta(array $meta)
    {
        $logEntry = json_encode([
            'batch_id'    => $meta['id'] ?? '',
            'parent_ids'  => $meta['parent_ids'] ?? [],
            'token_count' => $meta['token_count'] ?? 0,
            'files'       => array_keys($meta['file_map'] ?? []),
            'timestamp'   => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_SLASHES);

        $this->logMessage('BATCH_INFO', $logEntry);
    }


    public function logFullDiff(string $diffContent): void
    {
        $this->logMessage(self::LOGOUT_FLAG_BATCH_DIFF, $diffContent);
        $this->logMessage(self::LOGOUT_FLAG_BATCH_END, "\n");
    }

    public function logBatchStart(string $batchId, int $chunkCount): void
    {
        $this->logMessage("[BATCH {$batchId} START]", " 包含 {$chunkCount} 个代码段");
    }

    public function logBatchDiff(string $batchId, string $combinedDiff): void
    {
        $this->logMessage("[BATCH {$batchId} DIFF START]", "\n $combinedDiff");
        $this->logMessage("[BATCH {$batchId} DIFF END]", '');
    }

    public function logFinalReview(string $content)
    {
        $this->logMessage('[FINAL_REVIEW]', $content);
    }

}