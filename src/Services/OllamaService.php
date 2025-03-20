<?php

namespace MyCRB\Services;

use MyCRB\Handlers\LogHandler;
use Yethee\Tiktoken\EncoderProvider;

class OllamaService
{
    private array $config;

    // 动态上下文冗余 256 token
    const DYNAMIC_CONTEXT_REDUNDANT = 256;


    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function generateReview(string $diffContent, callable $streamHandler): string
    {
        $prompt     = str_replace('{diff}', $diffContent, $this->config['prompt']);
        $tokenCount = $this->countTokens($prompt);
        // 动态设置上下文长度，避免超出模型最大长度；$tokenCount已经包含prompt，额外增加部分冗余
        $dynamicContextLength = min($tokenCount + self::DYNAMIC_CONTEXT_REDUNDANT, $this->config['context_length']);
        $modelOptions         = array_merge([
            'temperature'    => 0.1,
            'top_p'          => 0.9,
            'repeat_penalty' => 1.1
        ], $this->config['model_params'] ?? []);
        $ch                   = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => rtrim($this->config['ollama_host'], '/') . '/api/generate',
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'   => $this->config['model_name'],
                'prompt'  => $prompt,
                'stream'  => true,
                'options' => array_merge(['num_ctx' => $dynamicContextLength], $modelOptions)
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => $streamHandler,
            CURLOPT_TIMEOUT        => 60 * 30 // 30分钟超时
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL request failed: " . $error);
        }
        if (empty($result)) {
            throw new \RuntimeException("Ollama returned empty response");
        }
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($statusCode >= 400) {
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed with status code: " . $statusCode);
        }
        curl_close($ch);
        return 'Review generation stream initiated successfully.';
    }


    /**
     * 统计代码的token数量
     * @param string $code
     * @return int
     */
    public function countTokens(string $code): int
    {
        $provider   = new EncoderProvider();
        $modelToUse = $this->config['tokenizer_model'];

        try {
            // 尝试使用配置的分词器模型
            $encoder = $provider->getForModel($modelToUse);
        } catch (\Exception $e) {
            // 如果配置的模型不支持，降级到指定的回退模型
            $modelToUse = $this->config['tokenizer_fallback'];
            try {
                $encoder = $provider->getForModel($modelToUse);
                // 记录降级信息
                error_log(
                    "警告: 模型 '{$this->config['tokenizer_model']}' 不被分词器支持，已自动降级到 '{$modelToUse}'"
                );
            } catch (\Exception $e2) {
                // 如果回退模型也不支持，使用cl100k_base编码器（最通用的编码器）
                $encoder = $provider->get('cl100k_base');
                error_log("警告: 回退模型 '{$modelToUse}' 也不被支持，已使用通用编码器 'cl100k_base'");
            }
        }

        return count($encoder->encode($code));
    }

    public function generateBatchReview(array $chunks, callable $streamHandler): string
    {
        $combinedContent = implode("\n\n", array_column($chunks, 'content'));
        return $this->generateReview($combinedContent, $streamHandler);
    }

    public function generateSummaryReview(array $partialResults, callable $streamHandler): string
    {
        $summaryPrompt = "请综合以下分批评审结果，给出最终评审意见：\n";
        foreach ($partialResults as $batchId => $result) {
            $summaryPrompt .= "## 批次 {$batchId} 评审结果\n{$result}\n\n";
        }

        $this->generateReview($summaryPrompt, $streamHandler);
        return $summaryPrompt;
    }
}