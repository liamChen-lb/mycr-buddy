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
            CURLOPT_TIMEOUT        => 300
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL request failed: " . $error);
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
        // 先统一使用 EncoderProvider 来分词，并且使用 'gpt-3.5-turbo-0301'模型
        $encoder = (new EncoderProvider())->getForModel('gpt-3.5-turbo-0301');

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