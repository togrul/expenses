<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\CreateTransaction;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints for the iPhone Shortcuts app ("Hey Siri, xərc yaz", Back Tap).
 * Authenticated with the personal token from Settings → Siri və qısayollar.
 */
class ShortcutController extends Controller
{
    /**
     * Category names for the Shortcut's "Choose from List" step.
     */
    public function categories(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $type = $request->query('type') === TransactionType::Income->value ? TransactionType::Income : TransactionType::Expense;

        return response()->json(
            $user->categories()->where('type', $type)->whereNull('archived_at')->ordered()->pluck('name'),
        );
    }

    public function store(Request $request, CreateTransaction $createTransaction): JsonResponse
    {
        $user = $this->user($request);

        if (is_float($request->input('amount')) || is_int($request->input('amount'))) {
            $request->merge(['amount' => (string) $request->input('amount')]);
        }

        $validated = $request->validate([
            'amount' => ['required', new MoneyAmount],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'category' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $type = $validated['type'] ?? TransactionType::Expense->value;
        $category = $this->findCategory($user, $type, $validated['category'] ?? null);
        $amount = Money::parse($validated['amount']);

        $createTransaction->handle($user, [
            'type' => $type,
            'amount' => $amount,
            'category_id' => $category?->id,
            'occurred_on' => CarbonImmutable::now(config('finance.timezone'))->toDateString(),
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'message' => __(':category: :amount yazıldı.', [
                'category' => $category->name ?? __('Kateqoriyasız'),
                'amount' => Money::format($amount),
            ]),
        ], 201);
    }

    private function user(Request $request): User
    {
        $token = (string) $request->bearerToken();
        $user = $token === '' ? null : User::query()->where('shortcut_token', hash('sha256', $token))->first();

        abort_if($user === null, 401, __('Qısayol açarı yanlışdır.'));

        return $user;
    }

    private function findCategory(User $user, string $type, ?string $name): ?Category
    {
        if (blank($name)) {
            return null;
        }

        $categories = $user->categories()->where('type', $type)->whereNull('archived_at')->get();
        $needle = mb_strtolower(trim($name));

        return $categories->first(fn (Category $category): bool => mb_strtolower($category->name) === $needle)
            ?? $categories->first(fn (Category $category): bool => str_contains(mb_strtolower($category->name), $needle));
    }
}
