<?php

namespace MyCRB\Services;

class DiffSplitter {
    const DIFF_HEADER_PATTERN = '/^diff --git a\/(.*?) b\/(.*?)/m';

    public function splitByFile(string $diff): array {
        $files = [];
        $currentFile = '';
        $lines = explode("\n", $diff);

        foreach ($lines as $line) {
            if (preg_match(self::DIFF_HEADER_PATTERN, $line, $matches)) {
                if ($currentFile !== '') {
                    $files[] = $currentFile;
                }
                $currentFile = $line."\n";
            } else {
                $currentFile .= $line."\n";
            }
        }

        if ($currentFile !== '') {
            $files[] = $currentFile;
        }

        return array_filter($files);
    }

    public function splitBySyntax(string $content, int $maxTokens): array {
        // AST解析实现待补充
        return [$content];
    }
}