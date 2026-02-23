<?php

namespace App\Console\Commands;

use App\Models\ImbalancePrice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchApgImbalancePrices extends Command
{
    protected $signature = 'fetch:apg-imbalance-prices {--days= : Number of days back to fetch (used only when table is empty for this EIC)}';
    protected $description = 'Fetches imbalance price data from APG API and upserts into imbalance_prices table.';

    private const EIC = '10YAT-APG------L';
    private const CURRENCY = 'EUR';
    private const UNIT = 'MWH';
    private const SOURCE = 'apg';
    private const TIMEZONE = 'Europe/Prague';

    private string $baseUrl;

    public function __construct()
    {
        parent::__construct();

        $this->baseUrl = config('services.apg.url');
    }

    public function handle(): int
    {
        $hasRecords = ImbalancePrice::where('eic', self::EIC)->exists();
        $daysOption = $this->option('days');

        /////////////////////////////////////////////////////////////////////////////////////////////////////////
        ///  Malá otázka – nechceme umožnit zadání počtu dnů vždy, bez ohledu na stav tabulky?               ///
        ///  Ve chvíli, kdy víme, že je tabulka prázdná a rozhodneme se získat data pro konkrétní období,   ///
        ///  mi ta podmínka již přijde nadbytečná. Každopádně řešení je uděláno podle zadání v PDF.        ///
        /////////////////////////////////////////////////////////////////////////////////////////////////////

        if (!$hasRecords && $daysOption !== null) {
            $days = (int) $daysOption;
            $startDate = Carbon::now(self::TIMEZONE)->startOfDay()->subDays($days);
            $endDate = Carbon::now(self::TIMEZONE)->addDay()->startOfDay();
        } else {
            $lastRecord = ImbalancePrice::where('eic', self::EIC)->max('time');

            if ($lastRecord === null) {
                $this->error('No records found for EIC ' . self::EIC . ' and --days option not provided.');
                Log::error('APG [Imbalance Prices] No records found for EIC ' . self::EIC . ' and --days option not provided.');

                return self::FAILURE;
            }

            $lastDate = Carbon::parse($lastRecord, self::TIMEZONE);
            $startDate = $lastDate->copy()->subDay()->startOfDay();
            $endDate = Carbon::now(self::TIMEZONE)->addDay()->startOfDay();
        }

        $startFormatted = $startDate->format('Y-m-d');
        $endFormatted = $endDate->format('Y-m-d');

        Log::info("APG [Imbalance Prices] Starting fetch from {$startFormatted} to {$endFormatted}");
        $this->info("Starting fetch from {$startFormatted} to {$endFormatted}");

        $currentDate = $startDate->copy();

        while ($currentDate->lt($endDate)) {
            $dayFrom = $currentDate->copy();
            $dayTo = $currentDate->copy()->addDay();

            $dayFormatted = $dayFrom->format('Y-m-d');
            Log::info("APG [Imbalance Prices] Fetching day {$dayFormatted}...");

            $fromLocal = $dayFrom->format('Y-m-d\THis');
            $toLocal = $dayTo->format('Y-m-d\THis');

            $url = "{$this->baseUrl}/AE/Data/English/PT15M/{$fromLocal}/{$toLocal}";

            try {
                // p_aeTimeSeriesQualityVersion - posílat teď nepotřebujeme. jde o sublevel pro kvalitu. jde o to kolikata verze korekce to je 
                $response = Http::get($url, [
                    'p_aeTimeSeriesQuality' => 'FirstValue',
                ]);

                if (!$response->successful()) {
                    Log::error("APG [Imbalance Prices] Error fetching day {$dayFormatted}: HTTP {$response->status()}");
                    $this->error("Error fetching day {$dayFormatted}: HTTP {$response->status()}");
                    $currentDate->addDay();

                    continue;
                }

                $data = $response->json();
                $rows = $data['ResponseData']['ValueRows'] ?? [];

                $upsertData = [];

                foreach ($rows as $row) {
                    if (empty($row['V']) || !isset($row['V'][0]['V']) || $row['V'][0]['V'] === null) {
                        continue;
                    }

                    $dateFrom = $row['DF'];
                    $timeFrom = $row['TF'];
                    $time = Carbon::createFromFormat('d.m.Y H:i', "{$dateFrom} {$timeFrom}", self::TIMEZONE);

                    $price = $row['V'][0]['V'];

                    $upsertData[] = [
                        'time' => $time,
                        'eic' => self::EIC,
                        'price_up' => $price, // kolik stojí dodat chybějící energii
                        'price_down' => $price, // kolik stojí energii ubrat. Rakousko publikuje jednu sjednocenou - vždy budou stejný
                        'currency' => self::CURRENCY,
                        'unit' => self::UNIT,
                        'source' => self::SOURCE,
                    ];
                }

                if (!empty($upsertData)) {
                    ImbalancePrice::upsert(
                        $upsertData,
                        ['time', 'eic', 'currency', 'source'],
                        ['price_up', 'price_down', 'unit']
                    );
                }

                $count = count($upsertData);
                Log::info("APG [Imbalance Prices] Day {$dayFormatted}: upserted {$count} rows");
                $this->info("Day {$dayFormatted}: upserted {$count} rows");
            } catch (\Exception $e) {
                Log::error("APG [Imbalance Prices] Error fetching day {$dayFormatted}: {$e->getMessage()}");
                $this->error("Error fetching day {$dayFormatted}: {$e->getMessage()}");
            }

            $currentDate->addDay();
        }

        Log::info('APG [Imbalance Prices] Fetch complete');
        $this->info('Fetch complete');

        return self::SUCCESS;
    }
}
