<?php

namespace App\Support;

use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use Carbon\CarbonImmutable;

/**
 * A single payable row in the combined "upcoming payments" list.
 */
final readonly class UpcomingItem
{
    public const string DebtPayment = 'debt_payment';

    public const string Occurrence = 'occurrence';

    public function __construct(
        public string $kind,
        public int $id,
        public string $title,
        public ?string $subtitle,
        public ?CarbonImmutable $dueOn,
        public int $amount,
        public bool $isPaid,
        public ?CarbonImmutable $paidAt,
        public ?int $paidAmount,
        public bool $isVariable,
        public int $remindDaysBefore,
        public string $url,
    ) {}

    public static function fromDebtPayment(DebtPayment $payment): self
    {
        $debt = $payment->debt;

        return new self(
            kind: self::DebtPayment,
            id: $payment->id,
            title: $debt->counterparty,
            subtitle: $debt->isLoan()
                ? __('Kredit').' · '.__(':n / :total ay', ['n' => $payment->installment_number, 'total' => $debt->term_months])
                : ($debt->title ?? __('Borc hissəsi')),
            dueOn: $payment->due_on ? CarbonImmutable::parse($payment->due_on) : null,
            amount: $payment->amount,
            isPaid: $payment->isPaid(),
            paidAt: $payment->paid_at ? CarbonImmutable::parse($payment->paid_at) : null,
            paidAmount: $payment->paid_amount,
            isVariable: false,
            remindDaysBefore: 3,
            url: $debt->url(),
        );
    }

    public static function fromOccurrence(RecurringOccurrence $occurrence): self
    {
        $recurring = $occurrence->recurringPayment;

        return new self(
            kind: self::Occurrence,
            id: $occurrence->id,
            title: $recurring->name,
            subtitle: $recurring->category->name ?? __('Daimi ödəniş'),
            dueOn: CarbonImmutable::parse($occurrence->due_on),
            amount: $occurrence->amount,
            isPaid: $occurrence->isPaid(),
            paidAt: $occurrence->paid_at ? CarbonImmutable::parse($occurrence->paid_at) : null,
            paidAmount: $occurrence->paid_amount,
            isVariable: $recurring->is_variable,
            remindDaysBefore: $recurring->remind_days_before,
            url: route('recurring.index'),
        );
    }

    public function key(): string
    {
        return "{$this->kind}-{$this->id}";
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid && $this->dueOn !== null && $this->dueOn->lt(CarbonImmutable::today());
    }

    /**
     * Due within the reminder window ("yaxınlaşır").
     */
    public function isDueSoon(): bool
    {
        return ! $this->isPaid
            && $this->dueOn !== null
            && ! $this->isOverdue()
            && $this->dueOn->lte(CarbonImmutable::today()->addDays($this->remindDaysBefore));
    }
}
