<?php

namespace App\Actions\Transactions;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class EnsureCategoryBelongsToUser
{
    /**
     * @throws ValidationException
     */
    public static function check(User $user, ?int $categoryId, ?string $type = null, string $field = 'category_id'): void
    {
        if ($categoryId === null) {
            return;
        }

        $exists = $user->categories()
            ->whereKey($categoryId)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => __('Seçilmiş kateqoriya mövcud deyil.')]);
        }
    }
}
