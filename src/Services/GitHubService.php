<?php

namespace MyCRB\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GitHubService
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getDiff(string $prUrl): string
    {
        $parsed = parse_url($prUrl);
        $pathParts = explode('/', trim($parsed['path'] ?? '', '/'));

        if (count($pathParts) < 4 || $pathParts[2] !== 'pull') {
            throw new \InvalidArgumentException("无效的PR URL格式");
        }

        list($owner, $repo, , $prNumber) = $pathParts;
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/pulls/{$prNumber}";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "User-Agent: PHP-PR-Reviewer/1.0",
                    "Authorization: Bearer {$this->config['github_token']}",
                    "Accept: application/vnd.github.v3.diff"
                ],
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new \RuntimeException("GitHub API请求失败: {$error['message']}");
        }

        $statusCode = $this->getStatusCodeFromHeader($http_response_header);
        if ($statusCode != 200) {
            throw new \RuntimeException("GitHub API返回错误: HTTP {$statusCode} - " . substr($response, 0, 512));
        }

        return $response;
    }

    private function getStatusCodeFromHeader(array $headers): int
    {
        foreach ($headers as $header) {
            if (strpos($header, 'HTTP/') === 0) {
                return (int)substr($header, 9, 3);
            }
        }
        return 0;
    }

    public function postComment(string $owner, string $repo, int $prNumber, string $content): void
    {
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$prNumber}/comments";

        $client = new Client();
        try {
            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['github_token'],
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'MyCR-Buddy/1.0'
                ],
                'json' => ['body' => $content],
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 201) {
                throw new \RuntimeException("GitHub评论提交失败: HTTP {$statusCode} - " . $response->getBody()->getContents());
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException("网络请求异常: " . $e->getMessage());
        }
    }
}