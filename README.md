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
- ✅ **新增** 历史评审提交功能（支持`-p pre`参数）
- ✅ **新增** 立即提交模式（`-p now`参数）
- ✅ **动态上下文计算**    根据代码差异自动调整模型上下文长度（`num_ctx = token_count + 1024`）
- ✅ **多模型分词适配**    支持Qwen2.5模型专用分词器（正则优化）

## 环境要求

- PHP 7.3+
- Ollama服务（推荐v0.5.13或最新版）
- cURL扩展
- JSON扩展

## 快速开始

### 1. 安装依赖

```bash
# 安装Ollama（macOS）
https://ollama.com/download/Ollama-darwin.zip

# 安装Ollama（Windows）
https://ollama.com/download/OllamaSetup.exe

# 下载模型（示例使用qwen2.5-coder:14b）
ollama pull qwen2.5-coder:14b
```

### 2. 项目配置

```bash
cp config/code_review_example.php config/code_review.php
```

编辑配置文件 `config/code_review.php`：

```php
return [
    'github_token'   => '你的GitHub Token',    // 需要repo权限
    'ollama_host'    => 'http://localhost:11434',
    'model_name'     => 'qwen2.5-coder:14b',   // 支持的模型名称
    'context_length' => 1024*8,                // 上下文长度（token数）
    'log_dir'        => dirname(__DIR__) . '/logs', // 日志目录
    'model_params'   => [
        'temperature'    => 0.1,  // 值越低输出越稳定（0.0-1.0）
        'top_p'          => 0.9,       // 控制采样范围（0.8-0.95最佳）
        'repeat_penalty' => 1.1 // 防止重复输出（1.0-1.2之间）
    ],
    'prompt' => <<<PROMPT
    // 自定义提示词模板...
    PROMPT
];
```

### 3. 运行审查

```bash
# 基础用法
php MyCRB.php https://github.com/username/repo/pull/123

# 立即生成并提交评审
php MyCRB.php https://github.com/username/repo/pull/123 -p now

# 提交历史评审记录
php MyCRB.php https://github.com/username/repo/pull/123 -p pre
```

## 使用示例

```bash
# 提交历史评审示例
$ php MyCRB.php https://github.com/example/test-repo/pull/42 -p pre
⚠️ 未找到历史评审记录，已生成新评审内容但未提交。使用 -p pre 提交本次结果；或使用 -p now 重新生成并提交

# 强制立即提交示例
$ php MyCRB.php https://github.com/example/test-repo/pull/42 -p now
✅ 接受 更改分数：85
[提交成功] 评审已发布到GitHub PR #42
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

| 配置项            | 说明                                          |
|----------------|---------------------------------------------|
| model_params   | 允许进一步设置模型参数，如温度、TopP等                       |
| github_token   | GitHub个人访问令牌（需repo权限）                       |
| ollama_host    | Ollama服务地址（默认`http://localhost:11434`）      |
| model_name     | 使用的模型名称（需提前通过`ollama pull`下载）               |
| context_length | 模型上下文长度（建议设为模型最大支持值）                        |
| log_dir        | 日志存储目录（需写权限）                                |
| prompt         | 审查提示词模板（`{diff}`占位符会自动替换为PR差异）              |
| **命令行参数**      | `-p now`：立即生成并提交评审<br>`-p pre`：提交最近一次历史评审记录 |

## 审查模式对比

| 模式     | 生成内容 | 提交行为    | 日志记录 |
|--------|------|---------|------|
| 无参数    | ✓    | ✗       | ✓    |
| -p now | ✓    | ✓（强制覆盖） | ✓    |
| -p pre | ✗    | ✓（历史记录） | ✓    |

## 日志关联功能

当使用 `-p pre` 参数时，系统会自动：

1. 搜索与当前PR关联的历史日志文件
2. 按时间倒序选择最近的有效评审记录
3. 自动格式化历史评审内容到GitHub评论

日志文件命名规则：  
`{PR_URL_sanitized}+{timestamp}.log`（示例：`https___github.com_project_pull_42+2024.03.08.15.30.00.log`）

## 注意事项

1. 首次使用需要下载模型（根据网络情况可能需要较长时间）：
   ```bash
   # 14B量化版（推荐）
   ollama pull qwen2.5-coder:14b
   
   # 32B完整版（需要32G+内存）
   ollama pull qwen2.5-coder:32b
   ```

2. 建议为Ollama配置GPU加速（需NVIDIA显卡和CUDA环境）

3. 审查质量取决于：
    - 模型规模（14B/32B）
    - 提示词设计
    - 上下文长度设置

4. 大模型响应速度参考：
    - 14B模型：约5-15秒/请求
    - 32B模型：约20-40秒/请求

5. 参数使用规范：
    - `-p now` 会强制重新生成评审内容并立即提交
    - `-p pre` 会尝试提交最近一次成功生成的评审记录
    - 未指定参数时仅生成评审内容不提交
