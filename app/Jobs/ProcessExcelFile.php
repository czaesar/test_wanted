<?php

namespace App\Jobs;

use App\Models\Row;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;


class ProcessExcelFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $chunkSize = 1000;
    protected $errors = [];
    protected $processedRows = 0;

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        set_time_limit(300);
        Log::info('Processing file: ' . $this->filePath);

        try {
            if (!file_exists($this->filePath)) {
                Log::error('File does not exist: ' . $this->filePath);
                throw new \Exception("File does not exist: " . $this->filePath);
            }

            $spreadsheet = IOFactory::load($this->filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            array_shift($rows);

            $totalRows = count($rows);

            foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
                foreach ($chunk as $rowNumber => $row) {
                    $this->processedRows++;
                    $validationResult = $this->validateRow($row);

                    if (empty($validationResult['errors'])) {
                        $this->saveRow($row, $validationResult['date']);
                    } else {
                        $this->errors[] = ($rowNumber + 2) . ' - ' . implode(', ', $validationResult['errors']);
                    }

                    // Обновление прогресса в Redis
                    Redis::set('progress:' . $this->filePath, json_encode([
                        'processed' => $this->processedRows,
                        'total' => $totalRows
                    ]));
                }
            }

            // Сохранение ошибок в файл
            Storage::put('result.txt', implode("\n", $this->errors));

            Log::info('Processing completed for file: ' . $this->filePath);
        } catch (\Exception $e) {
            Log::error('Error processing file: ' . $this->filePath . ' - ' . $e->getMessage());
            throw $e;
        }
    }
    protected function parseDate($dateString)
    {
        try {
            $date = Carbon::createFromFormat('d.m.Y', $dateString);
            return $date ? $date->format('Y-m-d') : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    protected function validateRow($row)
    {
        $errors = [];
        $parsedDate = null;

        try {
            $id = $row[0];
            $name = $row[1];
            $date = $row[2];

            if (!ctype_digit($id)) {
                $errors[] = "ID $id: Invalid ID";
            }

            if (!preg_match('/^[a-zA-Z ]+$/', $name)) {
                $errors[] = "ID $id: Invalid name";
            }

            $parsedDate = $this->parseDate($date, $id);
            if (!$parsedDate) {
                $errors[] = "ID $id: Invalid date";
                Log::error("Invalid date format for ID $id: $date");
            }
        } catch (\Exception $e) {
            $errors[] = "Error validating row: " . $e->getMessage();
            Log::error('Error validating row: ' . json_encode($row) . ' - ' . $e->getMessage());
        }

        return ['errors' => $errors, 'date' => $parsedDate];
    }

    protected function saveRow($row, $parsedDate)
    {
        try {
            if (!Row::where('id', $row[0])->exists() || $parsedDate != null) {
                Row::create([
                    'id' => $row[0],
                    'name' => $row[1],
                    'date' => $parsedDate,
                ]);
                Log::info('Row saved: ' . json_encode($row));
            } else {
                $this->errors[] = ($row[0]) . ' - Duplicate ID';
                Log::warning('Duplicate ID: ' . $row[0] . 'or invalid date');
            }
        } catch (\Exception $e) {
            Log::error('Error saving row: ' . json_encode($row) . ' - ' . $e->getMessage());
            throw $e;
        }
    }
}
