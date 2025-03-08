
# MyCR-Buddy - AI代码审查助手

[![PHP Version](https://img.shields.io/badge/PHP-7.3%2B-blue.svg)](https://php.net/)

智能化的代码审查助手，基于GitHub PR的差异分析，通过本地Ollama大模型提供代码审查建议。

## 功能特性

- ✅ 自动解析GitHub PR链接
- ✅ 实时流式AI响应
- ✅ 支持自定义审查提示词
- ✅ 自动生成带时间戳的日志
- ✅ 上下文长度可配置
- ✅ 支持多种开源大模型

## 环境要求

- PHP 7.3+
- Ollama服务（推荐v0.5.13 or latest）
- cURL扩展
- JSON扩展

## 快速开始

### 1. 安装依赖

```bash
https://github.com/ollama/ollama
# 安装Ollama（macOS）
https://ollama.com/download/Ollama-darwin.zip
# 安装Ollama（Windows）
https://ollama.com/download/OllamaSetup.exe
# 下载模型（示例使用qwen2.5）
ollama pull qwen2.5:14b
```

### 2. 项目配置

复制示例配置文件：
```bash
cp config/code_review_example.php config/code_review.php
```

编辑配置文件 `config/code_review.php`：
```php
return [
    'github_token'   => '你的GitHub Token',    // 需要repo权限
    'ollama_host'    => 'http://localhost:11434',
    'model_name'     => 'qwen2.5:14b',       // 支持的模型名称
    'context_length' => 1024*8,                // 上下文长度（token数）
    'log_dir' => dirname(__DIR__) . '/logs', // 日志目录
    'prompt' => <<<PROMPT                    // 自定义提示词
    // ...保持默认提示词结构...
];
```

### 3. 运行审查
```bash
php MyCRB.php https://github.com/username/repo/pull/123
```

## 使用示例

```bash
$ php MyCRB.php https://github.com/example/test-repo/pull/42

✅ 接受 更改分数：85
主要改进建议：
1. 在UserController.php第32行，建议添加输入验证：
   ```php
   + if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
   +     throw new InvalidArgumentException("无效的邮箱格式");
   + }
2. 数据库查询建议使用参数绑定防止SQL注入
   ```
   ...
```

## 日志系统

审查过程会自动生成日志文件：
```
logs/
├── https___github.com_example_repo_pull_20+2024.03.08.log
└── https___github.com_test_project_pull_15+2024.03.07.log
```

日志包含：
- 完整的差异内容
- AI响应流
- 执行时间统计
- 错误诊断信息

## 配置说明

| 配置项           | 说明                                                                 |
|------------------|--------------------------------------------------------------------|
| github_token     | GitHub个人访问令牌（需repo权限）                                     |
| ollama_host      | Ollama服务地址（默认http://localhost:11434）                        |
| model_name       | 使用的模型名称（需提前通过ollama pull下载）                           |
| context_length   | 模型上下文长度（建议设为模型最大支持值）                               |
| log_dir          | 日志存储目录（需写权限）                                              |
| prompt           | 审查提示词模板（{diff}占位符会自动替换为PR差异）                       |

## 注意事项

1. 首次使用需要下载模型（根据网络情况可能需要较长时间）：
   ```bash
   # 14B量化版（推荐）
   ollama pull qwen2.5:14b
   
   # 32B完整版（需要32G+内存）
   ollama pull qwen2.5:32b
   ```

2. 建议为Ollama配置GPU加速（需NVIDIA显卡和CUDA环境）

3. 审查质量取决于：
    - 模型规模（14B/32B）
    - 提示词设计
    - 上下文长度设置

4. 大模型响应速度参考：
    - 14B模型：约5-15秒/请求
    - 32B模型：约20-40秒/请求