<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class TransactionCsvImporter
{
    /**
     * @var array<string, string>
     */
    public const DATE_FORMATS = [
        'Y-m-d' => 'YYYY-MM-DD (2026-10-28)',
        'd/m/Y' => 'DD/MM/YYYY (28/10/2026)',
        'm/d/Y' => 'MM/DD/YYYY (10/28/2026)',
        'd-m-Y' => 'DD-MM-YYYY (28-10-2026)',
        'm-d-Y' => 'MM-DD-YYYY (10-28-2026)',
        'd.m.Y' => 'DD.MM.YYYY (28.10.2026)',
        'Y/m/d' => 'YYYY/MM/DD (2026/10/28)',
    ];

    /**
     * @var list<string>
     */
    public const COLUMN_LABELS = [
        'First',
        'Second',
        'Third',
    ];

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, column_count: int, has_header: bool}
     */
    public function read(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $trimmed = $this->trimRow($row);

            if ($trimmed === []) {
                continue;
            }

            $rows[] = $trimmed;
        }

        fclose($handle);

        if ($rows === []) {
            throw new InvalidArgumentException('The CSV file is empty.');
        }

        $columnCount = max(array_map('count', $rows));

        if (! in_array($columnCount, [2, 3], true)) {
            throw new InvalidArgumentException('The CSV must have 2 or 3 columns.');
        }

        $hasHeader = ! $this->rowLooksLikeData($rows[0]);
        $headers = $hasHeader ? $rows[0] : [];

        if ($hasHeader) {
            $rows = array_slice($rows, 1);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The CSV file has no transactions.');
        }

        while (count($headers) < $columnCount) {
            $headers[] = 'Column '.(count($headers) + 1);
        }

        $headers = array_values(array_slice($headers, 0, $columnCount));
        $normalized = [];

        foreach ($rows as $row) {
            $normalized[] = array_pad(array_slice($row, 0, $columnCount), $columnCount, '');
        }

        return [
            'headers' => $headers,
            'rows' => $normalized,
            'column_count' => $columnCount,
            'has_header' => $hasHeader,
        ];
    }

    /**
     * @param  list<string>  $row
     * @return array{date: ?int, amount: ?int, type: ?int}
     */
    public function suggestMapping(array $row, int $columnCount): array
    {
        $date = null;
        $amount = null;
        $type = null;

        foreach ($row as $index => $cell) {
            if ($date === null && $this->looksLikeDate($cell)) {
                $date = $index;

                continue;
            }

            $parsedAmount = $this->parseAmount($cell);

            if ($amount === null && $parsedAmount !== null && ! $parsedAmount['zero']) {
                $amount = $index;

                continue;
            }

            if ($columnCount === 3 && $type === null && $this->parseType($cell) !== null) {
                $type = $index;
            }
        }

        return [
            'date' => $date,
            'amount' => $amount,
            'type' => $type,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<array{index: int, line: int, transaction_date: ?string, type: ?string, amount: ?string, errors: list<string>, valid: bool}>
     */
    public function preview(array $rows, int $dateColumn, int $amountColumn, ?int $typeColumn, bool $hasHeader, string $dateFormat): array
    {
        $preview = [];

        foreach ($rows as $index => $row) {
            $errors = [];
            $rawDate = $row[$dateColumn] ?? '';
            $rawAmount = $row[$amountColumn] ?? '';
            $parsedDate = $this->parseDate($rawDate, $dateFormat);
            $parsedAmount = $this->parseAmount($rawAmount);

            if ($rawDate === '' || $parsedDate === null) {
                $errors[] = 'Date is missing or is not a valid date.';
            }

            if ($rawAmount === '' || $parsedAmount === null) {
                $errors[] = 'Amount is missing or is not a valid number.';
            } elseif ($parsedAmount['zero']) {
                $errors[] = 'Amount must be greater than 0.';
            } elseif ($parsedAmount['too_many_decimals']) {
                $errors[] = 'Amount must have at most 2 decimal places.';
            }

            $type = null;
            $amount = null;

            if ($parsedAmount !== null && ! $parsedAmount['zero'] && ! $parsedAmount['too_many_decimals']) {
                $amount = $parsedAmount['amount'];
            }

            if ($typeColumn === null) {
                if ($amount !== null) {
                    $type = $parsedAmount['negative'] ? 'expense' : 'income';
                }
            } else {
                $rawType = $row[$typeColumn] ?? '';
                $type = $this->parseType($rawType);

                if ($type === null) {
                    $errors[] = 'Type must be income or expense.';
                } elseif ($parsedAmount !== null && $amount !== null && $parsedAmount['explicit_sign'] && ($parsedAmount['negative'] ? 'expense' : 'income') !== $type) {
                    $errors[] = 'Type does not match the amount sign.';
                }
            }

            $preview[] = [
                'index' => $index,
                'line' => $index + ($hasHeader ? 2 : 1),
                'transaction_date' => $parsedDate,
                'type' => $type,
                'amount' => $amount,
                'errors' => $errors,
                'valid' => $errors === [],
            ];
        }

        return $preview;
    }

    /**
     * @param  list<string|null>  $row
     * @return list<string>
     */
    private function trimRow(array $row): array
    {
        $cells = array_map(fn ($cell) => trim((string) $cell), $row);

        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return array_values($cells);
    }

    /**
     * @param  list<string>  $row
     */
    private function rowLooksLikeData(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->looksLikeDate($cell)) {
                return true;
            }

            $amount = $this->parseAmount($cell);

            if ($amount !== null) {
                return true;
            }
        }

        return false;
    }

    public function parseDate(string $value, string $format): ?string
    {
        $value = trim($value);

        if ($value === '' || ! array_key_exists($format, self::DATE_FORMATS)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        if ($date->format($format) !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function looksLikeDate(string $value): bool
    {
        foreach (array_keys(self::DATE_FORMATS) as $format) {
            if ($this->parseDate($value, $format) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ?array{amount: string, negative: bool, explicit_sign: bool, zero: bool, too_many_decimals: bool}
     */
    public function parseAmount(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $negative = false;
        $explicitSign = false;

        if (preg_match('/^\((.+)\)$/', $value, $matches) === 1) {
            $negative = true;
            $explicitSign = true;
            $value = trim($matches[1]);
        }

        if (preg_match('/^([+-])\s*(.+)$/', $value, $matches) === 1) {
            $explicitSign = true;
            $negative = $matches[1] === '-' || $negative;
            $value = trim($matches[2]);
        }

        $value = str_replace([',', ' ', '$', '₱', 'PHP', 'php'], '', $value);

        if (preg_match('/^\d+\.\d{3,}$/', $value) === 1) {
            return [
                'amount' => '',
                'negative' => $negative,
                'explicit_sign' => $explicitSign,
                'zero' => false,
                'too_many_decimals' => true,
            ];
        }

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        $zero = (float) $value === 0.0;

        return [
            'amount' => number_format((float) $value, 2, '.', ''),
            'negative' => $negative,
            'explicit_sign' => $explicitSign,
            'zero' => $zero,
            'too_many_decimals' => false,
        ];
    }

    private function parseType(string $value): ?string
    {
        $type = strtolower(trim($value));

        return in_array($type, ['income', 'expense'], true) ? $type : null;
    }
}
