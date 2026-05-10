<?php

require __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Reader\Common\Creator\ReaderFactory;

$path = $argv[1] ?? null;
if (! $path) {
    fwrite(STDERR, "Usage: php scripts/inspect_excel.php <path-to-xlsx>\n");
    exit(2);
}

$reader = ReaderFactory::createFromFile($path);
$reader->open($path);

foreach ($reader->getSheetIterator() as $sheet) {
    $rowNum = 0;
    foreach ($sheet->getRowIterator() as $row) {
        $rowNum++;
        $vals = [];
        foreach ($row->getCells() as $cell) {
            $vals[] = $cell->getValue();
        }
        echo $rowNum . ': ' . json_encode($vals, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if ($rowNum >= 5) {
            break;
        }
    }
    break;
}

$reader->close();

