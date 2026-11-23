<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\CreateTransaction;
use App\Enums\TransactionType;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receives the transactions the installed app saved on the phone while it was offline.
 * Each entry carries a client id, so a retried upload never creates a duplicate.
 */
class OfflineTransactionController extends Controller
{
    public function __invoke(Request $request, CreateTransaction $createTransaction): JsonResponse
    {
        $request->validate([
            'transactions' => ['required', 'array', 'max:100'],
            'transactions.*.id' => ['required', 'uuid'],
        ]);

        $user = $request->user();
        $synced = [];
        $rejected = [];

        foreach ($request->input('transactions') as $entry) {
            $key = "offline-transaction:{$user->id}:{$entry['id']}";

            if (Cache::has($key)) {
                $synced[] = $entry['id'];

                continue;
            }

            $validator = Validator::make($entry, [
                'type' => ['required', Rule::enum(TransactionType::class)],
                'amount' => ['required', new MoneyAmount],
                'category_id' => ['nullable', 'integer'],
                'occurred_on' => ['required', 'date'],
                'note' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                $rejected[] = $entry['id'];

                continue;
            }

            $data = $validator->validated();

            try {
                $createTransaction->handle($user, [
                    'type' => $data['type'],
                    'amount' => Money::parse($data['amount']),
                    'category_id' => $data['category_id'] ?? null,
                    'occurred_on' => $data['occurred_on'],
                    'note' => $data['note'] ?? null,
                ]);
            } catch (ValidationException) {
                $rejected[] = $entry['id'];

                continue;
            }

            Cache::put($key, true, now()->addDays(30));
            $synced[] = $entry['id'];
        }

        return response()->json(['synced' => $synced, 'rejected' => $rejected]);
    }
}
