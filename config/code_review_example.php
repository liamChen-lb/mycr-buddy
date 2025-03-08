<?php

return [
    'github_token'   => 'ghp_xxx',
    'ollama_host'    => 'http://localhost:11434',
    // 若32b执行较慢，可以尝试14b
        'model_name'     => 'qwen2.5:14b',
//    'model_name'     => 'qwen2.5:32b',
    'context_length' => 1024 * 8,
    'log_dir' => dirname(__DIR__) . '/logs',

    'prompt'         => <<<PROMPT
您是高级编程专家Bot，负责审查代码更改并提供审查建议。
在建议开始时，需要明确做出“拒绝”或“接受”代码变更的决定，并以“更改分数：实际分数”的形式对变化进行评分，评分范围为0-100分。
然后，用简洁的语言和严厉的语气指出存在的问题。如果您觉得有必要，可以直接提供修改后的内容。
您的审查提案必须使用严格的Markdown格式。请用中文描述并回答。
查看以下代码更改
{diff}
PROMPT

];
