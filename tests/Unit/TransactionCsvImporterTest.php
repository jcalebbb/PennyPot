<?php

namespace Tests\Unit;

use App\Services\TransactionCsvImporter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransactionCsvImporterTest extends TestCase
{
    public function test_it_reads_a_two_column_file_and_infers_type_from_the_amount_sign(): void
    {
        $parsed = $this->importer()->read("Date,Amount\n2026-10-01,-42.50\n2026-10-02,+1500\n");

        $this->assertSame(['Date', 'Amount'], $parsed['headers']);
        $this->assertSame(2, $parsed['column_count']);
        $this->assertTrue($parsed['has_header']);

        $preview = $this->importer()->preview($parsed['rows'], 0, 1, null, true, 'Y-m-d');

        $this->assertTrue($preview[0]['valid']);
        $this->assertSame('expense', $preview[0]['type']);
        $this->assertSame('42.50', $preview[0]['amount']);
        $this->assertSame('2026-10-01', $preview[0]['transaction_date']);

        $this->assertTrue($preview[1]['valid']);
        $this->assertSame('income', $preview[1]['type']);
        $this->assertSame('1500.00', $preview[1]['amount']);
        $this->assertSame('2026-10-02', $preview[1]['transaction_date']);
    }

    public function test_it_warns_when_a_three_column_type_disagrees_with_an_explicit_amount_sign(): void
    {
        $parsed = $this->importer()->read("date,amount,type\n2026-10-01,80.00,expense\n2026-10-02,-15.00,income\n2026-10-03,10.00,transfer\n");
        $preview = $this->importer()->preview($parsed['rows'], 0, 1, 2, true, 'Y-m-d');

        $this->assertTrue($preview[0]['valid']);
        $this->assertSame('expense', $preview[0]['type']);
        $this->assertSame('80.00', $preview[0]['amount']);

        $this->assertFalse($preview[1]['valid']);
        $this->assertContains('Type does not match the amount sign.', $preview[1]['errors']);

        $this->assertFalse($preview[2]['valid']);
        $this->assertContains('Type must be income or expense.', $preview[2]['errors']);
    }

    public function test_it_warns_for_zero_and_invalid_dates(): void
    {
        $parsed = $this->importer()->read("2026-10-03,0\n2026-13-40,10\n");

        $this->assertFalse($parsed['has_header']);
        $this->assertSame(['Column 1', 'Column 2'], $parsed['headers']);

        $preview = $this->importer()->preview($parsed['rows'], 0, 1, null, false, 'Y-m-d');

        $this->assertContains('Amount must be greater than 0.', $preview[0]['errors']);
        $this->assertContains('Date is missing or is not a valid date.', $preview[1]['errors']);
    }

    public function test_it_rejects_files_that_do_not_have_two_or_three_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The CSV must have 2 or 3 columns.');

        $this->importer()->read("a,b,c,d\n1,2,3,4\n");
    }

    #[DataProvider('dates')]
    public function test_it_parses_supported_dates(string $value, string $format, ?string $expected): void
    {
        $this->assertSame($expected, $this->importer()->parseDate($value, $format));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function dates(): array
    {
        return [
            'iso' => ['2026-10-01', 'Y-m-d', '2026-10-01'],
            'day month year' => ['01/02/2026', 'd/m/Y', '2026-02-01'],
            'month day year' => ['01/02/2026', 'm/d/Y', '2026-01-02'],
            'mismatched format' => ['01/02/2026', 'Y-m-d', null],
            'invalid' => ['Date', 'Y-m-d', null],
        ];
    }

    private function importer(): TransactionCsvImporter
    {
        return new TransactionCsvImporter;
    }
}
