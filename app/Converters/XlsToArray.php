<?php

declare(strict_types=1);

namespace App\Converters;

use App\ConversionResult;
use App\Exceptions\PriceListValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use SplFileObject;

abstract class XlsToArray
{
    use CanConvertToFish;

    protected const NUMBER_OF_SHEETS_WE_NEED = 5;

    protected ?array $images;
    protected string $placeholder_image = 'https://i.ibb.co/9tpYXHz/fish-placeholder.jpg';

    public function __construct(protected string $pathname, protected Xlsx $xlsx_reader)
    {
        $this->images = [
            'fish' => $this->getImagesFromCSV('fish'),
            'equipment' => $this->getImagesFromCSV('equipment'),
            'feed' => $this->getImagesFromCSV('feed'),
            'chemistry' => $this->getImagesFromCSV('chemistry'),
            'aquariums' => $this->getImagesFromCSV('aquariums'),
        ];
    }

    /**
     * @return \App\ConversionResult
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \Exception
     * @throws \App\Exceptions\PriceListValidationException convertTo method throws it
     */
    public function convert(): ConversionResult
    {
        $sheets = $this->getArrayFromSheet($this->xlsx_reader->load($this->pathname));

        return new ConversionResult(
            $this->convertToFish($sheets['fish']),
            $this->convertTo($sheets['equipment'], ['name', 'description', 'producer', 'price'], 'equipment'),
            $this->convertTo($sheets['feed'], ['name', 'description', 'weight', 'price'], 'feed'),
            $this->convertTo($sheets['chemistry'], ['name', 'capacity', 'description', 'price'], 'chemistry'),
            $this->convertTo($sheets['aquariums'], ['name', 'capacity', 'description', 'price'], 'aquariums'),
        );
    }

    protected function getImagesFromCSV(string $file_name): ?array
    {
        $file_path = storage_path("app/csv/$file_name.csv");

        if (!file_exists($file_path)) {
            return null;
        }

        $file = new SplFileObject($file_path);

        if (is_null($file)) {
            return null;
        }

        $result = [];

        while (!$file->eof()) {
            $csv = $file->fgetcsv('|');

            if (count($csv) !== 2) {
                continue;
            }

            $result[mb_strtolower(current($csv))] = last($csv);
        }

        return $result;
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $sheets
     *
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    protected function getArrayFromSheet(Spreadsheet $sheets): array
    {
        $categories = ['fish', 'equipment', 'feed', 'chemistry', 'aquariums'];

        $result = [];

        for ($sheet_index = 0; $sheet_index < self::NUMBER_OF_SHEETS_WE_NEED; $sheet_index++) {
            $sheet = $sheets->getSheet($sheet_index);
            $index = 0;

            foreach ($sheet->getColumnIterator() as $column) {
                foreach ($column->getCellIterator() as $cell) {
                    $category = $categories[$sheet_index];
                    $result[$category][$index][] = $cell->getValue();
                }

                $index++;
            }
        }

        return $result;
    }

    protected function getImageFrom(?string $article, string $images_category): ?string
    {
        $id = mb_strtolower(preg_replace('!\s+!', ' ', trim($article ?? '')));
        return $this->images[$images_category][$id] ?? $this->placeholder_image;
    }

    protected function getNotNulls(array $columns): array
    {
        $result = array_filter($columns, static fn ($item) =>
            !is_null($item) && $item !== '' && $item !== '0.00'
        );

        return array_values($result);
    }

    protected function stringIsCategory(?string $str): bool
    {
        $str = $str ? trim($str) : '';
        return str_starts_with($str, '~');
    }

    protected function stringIsSubCategory(?string $str): bool
    {
        $str = $str ? trim($str) : '';
        return str_starts_with($str, '*');
    }

    protected function removeMultipleSpaces(?string $string): string
    {
        return preg_replace('/\s\s+/', ' ', $string ?? '');
    }

    /**
     * @param string|int|float|\PhpOffice\PhpSpreadsheet\RichText\RichText $article
     * @param array[] $items
     * @param string[] $column_names
     * @param int $i Iteration index
     *
     * @return string[]
     */
    protected function getColumns(string|int|float|RichText $article, array $items, array $column_names, int $i): array
    {
        $article = $article instanceof RichText ? $article->getPlainText() : (string) $article;
        $result = ['article' => trim($article)];

        $index = 1;

        foreach ($column_names as $name) {
            $value = $items[$index][$i];
            $result[$name] = is_string($value) ? trim($value) : $value;
            $index++;
        }

        return $result;
    }

    /**
     * @param string|bool $title
     * @param string $next_article
     *
     * @throws \App\Exceptions\PriceListValidationException
     */
    protected function throwIfTitleDoesntHaveSpecialCharacters(string|bool $title, string $next_article): void
    {
        if (empty($next_article)) {
            return;
        }

        if (is_bool($title)) {
            throw new PriceListValidationException(<<<MSG
            Проверте правильность прайса. Убедитесь что нет пустых строк, категорий и подкатегорий.
            MSG);
        }

        if (!$this->stringIsCategory($title) && !$this->stringIsSubCategory($title)) {
            throw new PriceListValidationException(<<<MSG
            Проверте правильность ввода категории или подкатегории "$title".
            Каждая категория должна начинаться с символа ~, а подкатегория с символа *.
            MSG);
        }
    }
}