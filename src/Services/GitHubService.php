<?php

namespace MyCRB\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GitHubService
{
    private array $config;

    public function __construct(array $config)
    {
        if (!isset($config['github_token'])) {
            throw new \InvalidArgumentException('GitHub token未配置');
        }
        $this->config = $config;
    }

    public function getDiff(string $prUrl): string
    {
        $parsed    = parse_url($prUrl);
        $pathParts = explode('/', trim($parsed['path'] ?? '', '/'));

        if (count($pathParts) < 4 || $pathParts[2] !== 'pull') {
            throw new \InvalidArgumentException("无效的PR URL格式");
        }

        list($owner, $repo, , $prNumber) = $pathParts;
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/pulls/{$prNumber}";

        $client = new Client([
            'timeout' => 30 // 新增30秒超时设置
        ]);

        try {
            $response = $client->get($apiUrl, [
                'headers'     => [
                    "User-Agent"    => "PHP-PR-Reviewer/1.0",
                    "Authorization" => "Bearer {$this->config['github_token']}",
                    "Accept"        => "application/vnd.github.v3.diff"
                ],
                'http_errors' => false // 保持原有错误处理逻辑
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException(
                    "GitHub API返回错误: HTTP {$statusCode} - " . $response->getBody()->getContents()
                );
            }

            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new \RuntimeException("GitHub API请求失败: " . $e->getMessage());
        }
    }

    public function postComment(string $owner, string $repo, int $prNumber, string $content): void
    {
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$prNumber}/comments";

        $client = new Client([
            'timeout' => 30
        ]);
        try {
            $response = $client->post($apiUrl, [
                'headers'     => [
                    'Authorization' => 'Bearer ' . $this->config['github_token'],
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'MyCRB-Buddy/1.0'
                ],
                'json'        => ['body' => $content],
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 201) {
                throw new \RuntimeException(
                    "GitHub评论提交失败: HTTP {$statusCode} - " . $response->getBody()->getContents()
                );
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException("网络请求异常: " . $e->getMessage());
        }
    }
}