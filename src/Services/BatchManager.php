<?php

namespace MyCRB\Services;

class BatchManager
{
    private $contextLength;

    public function __construct(int $contextLength)
    {
        $this->contextLength = $contextLength;
    }

    public function createBatches(array $chunks): array
    {
        $batches      = [];
        $currentBatch = [
            'id'          => uniqid('batch_'),
            'chunks'      => [],
            'token_count' => 0,
            'file_map'    => []
        ];

        foreach ($chunks as $chunk) {
            // TODO: 这里的256应该作为一个参数或者配置项
            $required = $chunk['token_count'] + 256; // 保留256token给系统prompt

            // 大小超出，则需要分批
            if (($currentBatch['token_count'] + $required) > $this->contextLength) {
                $batches[]    = $currentBatch;
                $currentBatch = [
                    'id'          => uniqid('batch_'),
                    'chunks'      => [],
                    'token_count' => 0,
                    'file_map'    => []
                ];
            }

            $currentBatch['chunks'][]                 = $chunk;
            $currentBatch['token_count']              += $chunk['token_count'];
            $currentBatch['file_map'][$chunk['file']] = true;
        }

        if (!empty($currentBatch['chunks'])) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }
}