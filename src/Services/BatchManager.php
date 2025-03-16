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
            $required = $chunk['token_count'] + 256; // 保留200token给系统prompt

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