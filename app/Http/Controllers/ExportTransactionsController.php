<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\Preferences;
use App\Support\TransactionFilters;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportTransactionsController extends Controller
{
    /**
     * Stream the filtered transactions as a UTF-8 CSV (Excel friendly, ";" separated).
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', 'in:income,expense'],
            'category' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $filters = new TransactionFilters(
            month: $validated['month'] ?? null,
            from: $validated['from'] ?? null,
            to: $validated['to'] ?? null,
            type: $validated['type'] ?? null,
            categoryId: isset($validated['category']) ? (int) $validated['category'] : null,
            search: $validated['q'] ?? null,
        );

        $user = $request->user();
        $filename = 'emeliyyatlar-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($filters, $user): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [__('Tarix'), __('Növ'), __('Kateqoriya'), __('Məbləğ'), __('Valyuta'), __('Qeyd')], ';');

            $filters->apply($user)
                ->with('category')
                ->orderBy('occurred_on')
                ->orderBy('id')
                ->lazy()
                ->each(function (Transaction $transaction) use ($handle): void {
                    fputcsv($handle, [
                        $transaction->occurred_on->format(Preferences::dateFormat()),
                        $transaction->type->label(),
                        $transaction->category?->name,
                        ($transaction->type === TransactionType::Expense ? '-' : '').Money::toInput($transaction->amount),
                        $transaction->currency,
                        $transaction->note,
                    ], ';');
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
