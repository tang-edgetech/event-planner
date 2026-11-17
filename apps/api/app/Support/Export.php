<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / JSON downloads. CSV starts with a UTF-8 BOM so Excel opens accents correctly,
 * and cells starting with = + - @ are prefixed so spreadsheets never run them as formulas.
 */
class Export
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $columns  key => heading
     */
    public static function download(array $rows, array $columns, string $basename, string $format): Response
    {
        if ($format === 'json') {
            $payload = array_map(fn ($row) => array_intersect_key($row, $columns) + array_diff_key($row, $columns), $rows);

            return response()->json($payload, 200, [
                'Content-Disposition' => 'attachment; filename="'.$basename.'.json"',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return new StreamedResponse(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($columns), ',', '"', '\\');
            foreach ($rows as $row) {
                $line = [];
                foreach (array_keys($columns) as $key) {
                    $line[] = self::cell($row[$key] ?? '');
                }
                fputcsv($out, $line, ',', '"', '\\');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$basename.'.csv"',
        ]);
    }

    private static function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        return $value;
    }
}
