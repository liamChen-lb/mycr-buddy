<?php

class Example001 {
    public function fetchData($data) {
        // 循环中每次都调用 count()，存在性能隐患
        for ($i = 0; $i < count($data); $i++){
            // 未校验 $data[$i] 是否存在，可能导致 Notice
            if($data[$i]['score'] > 50){
                echo "High score: " . $data[$i]['score'];
            }
        }
    }
}

class Processor {
    public function process($input){
        if($input == null){
            echo "Invalid input";
        }
        // 未使用大括号包裹 if 语句，且运算符两侧无空格，不符合 PSR-12
        if($input['value']>10) echo "Value is large";
    }
}

class DataHandler {
    public function handle($data){
        // 潜在的语法错误：缺少分号
        $result = $data * 2
        echo $result;
    }
}
