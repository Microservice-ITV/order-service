<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\CategoryTaxCharge;
use App\Models\TaxChargeSetting;
use App\Services\RabbitMQ\BaseConsumer;
use Illuminate\Support\Facades\DB;

class ConsumeTaxCharges extends BaseConsumer
{
    protected $signature = 'consume:tax-charges';

    protected $description = 'Store product-category tax rates published by tax-service';

    protected function routingKeys(): array
    {
        return [EventTopic::TAX_CHARGES_UPDATED->value];
    }

    protected function queue(): string
    {
        return (string) config('rabbitmq.queues.tax_charges');
    }

    public function process(array $payload): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $enabled = (bool) ($data['is_enabled'] ?? true);
        $currency = substr((string) ($data['currency'] ?? 'MMK'), 0, 8);
        $charges = is_array($data['charges'] ?? null) ? $data['charges'] : [];

        DB::transaction(function () use ($enabled, $currency, $charges): void {
            TaxChargeSetting::query()->updateOrCreate(
                ['id' => 'default'],
                [
                    'is_enabled' => $enabled,
                    'currency' => $currency,
                    'updated_at' => now(),
                ],
            );

            CategoryTaxCharge::query()->delete();

            $rows = [];
            $seen = [];
            foreach ($charges as $charge) {
                if (!is_array($charge)) {
                    continue;
                }
                $categoryId = trim((string) ($charge['category_id'] ?? ''));
                if ($categoryId === '' || isset($seen[$categoryId])) {
                    continue;
                }
                $seen[$categoryId] = true;
                $rows[] = [
                    'category_id' => $categoryId,
                    'tax_id' => (string) ($charge['tax_id'] ?? ''),
                    'tax_name' => substr((string) ($charge['name'] ?? ''), 0, 120),
                    'rate' => round((float) ($charge['rate'] ?? 0), 4),
                    'is_active' => (bool) ($charge['is_active'] ?? true),
                    'category_name' => isset($charge['category_name'])
                        ? substr((string) $charge['category_name'], 0, 255)
                        : null,
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                CategoryTaxCharge::query()->insert($rows);
            }
        });
    }
}
