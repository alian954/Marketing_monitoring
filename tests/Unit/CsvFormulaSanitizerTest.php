<?php

namespace Tests\Unit;

use App\Services\CsvFormulaSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvFormulaSanitizerTest extends TestCase
{
    #[DataProvider('formulaDangerousValues')]
    public function test_formula_prefixes_are_made_text_safe(string $value): void
    {
        $this->assertSame("'".$value, CsvFormulaSanitizer::escape($value));
    }

    /** @return array<string, array{string}> */
    public static function formulaDangerousValues(): array
    {
        return [
            'equals sign' => ['=1+1'],
            'plus sign' => ['+1+1'],
            'minus sign' => ['-1+1'],
            'at sign' => ['@SUM(1)'],
            'leading tab' => ["\t=1+1"],
            'leading carriage return' => ["\r=1+1"],
            'leading line feed' => ["\n=1+1"],
            'leading control and spaces' => ["\t\r\n =1+1"],
            'full-width equals sign' => ['＝1+1'],
            'full-width plus sign' => ['＋1+1'],
            'full-width minus sign' => ['－1+1'],
            'full-width at sign' => ['＠SUM(1)'],
            'ideographic space before full-width formula' => ["\u{3000}＝1+1"],
        ];
    }

    public function test_values_without_a_formula_prefix_remain_unchanged(): void
    {
        foreach (['', 'Campaign + launch', '  Campaign', "' =1+1", '活動更新', '-66.6667'] as $value) {
            $this->assertSame($value, CsvFormulaSanitizer::escape($value));
        }
    }
}
