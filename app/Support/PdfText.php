<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class PdfText
{
    public static function extract(string $path): array
    {
        try {
            $process = new Process([config('services.pdf.node_binary'), '--max-old-space-size=256', base_path('resources/js/extract-pdf.mjs'), $path], base_path(), null, null, 40);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            return ['pdf_text' => $result['text'], 'pdf_text_status' => $result['status'], 'page_count' => $result['pages']];
        } catch (\Throwable) {
            return ['pdf_text' => null, 'pdf_text_status' => 'failed', 'page_count' => null];
        }
    }
}
