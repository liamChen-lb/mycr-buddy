<?php

namespace MyCRB\Services;

use MyCRB\Handlers\LogHandler;
use Yethee\Tiktoken\EncoderProvider;

class OllamaService
{
    private $config;
    private LogHandler $logHandler;


    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function generateReview(string $diffContent, callable $streamHandler): string
    {
        $prompt     = str_replace('{diff}', $diffContent, $this->config['prompt']);
        $tokenCount = $this->countTokens($prompt);
        // 动态设置上下文长度，避免超出模型最大长度；并且考虑了prompt的占用，增加256作为冗余
        $dynamicContextLength = min($tokenCount + 256, $this->config['context_length']);
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

        return curl_exec($ch);
    }

    /**
     * 根据模型名称获取分词器
     * @param string $modelName
     * @return callable
     */
    public function getTokenizer(string $modelName): callable
    {
        switch (strtolower($modelName)) {
            case 'qwen2.5-coder:14b':
            case 'qwen2.5-coder:32b':
                return function ($text) {
                    // 改进代码符号处理（正则优化）
                    return preg_split('/(?<!\w)(?=\W)|(?<=\W)(?!\w)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
                };
            default:
                return function ($text) {
                    // 中文单独切分（通用优化）
                    $text = preg_replace('/\s+/u', ' ', $text);
                    return preg_split('/(\s+|[^\p{L}\p{N}_]+)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
                };
        }
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