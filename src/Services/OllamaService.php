<?php

namespace MyCRB\Services;

class OllamaService
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function generateReview(string $diffContent, callable $streamHandler): string
    {
        $prompt = str_replace('{diff}', $diffContent, $this->config['prompt']);
        $modelOptions = array_merge([
            'temperature' => 0.1,
            'top_p' => 0.9,
            'repeat_penalty' => 1.1
        ], $this->config['model_params'] ?? []);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->config['ollama_host'], '/') . '/api/generate',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->config['model_name'],
                'prompt' => $prompt,
                'stream' => true,
                'options' => array_merge(['num_ctx' => $this->config['context_length']], $modelOptions)
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => $streamHandler,
            CURLOPT_TIMEOUT => 300
        ]);

        return curl_exec($ch);
    }
}