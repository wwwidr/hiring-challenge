# PLAN.md — TICKET-003: Payment Confirmation Notification with Status Gates

## Questions before building

Before writing a line of code, three things in this spec need clarification:

**Q1 — Why is the gate `active | installment` and not the full `isActive()` set?**

`Sequence::ACTIVE_STATUSES` already includes `partially_paid_recovery` and `will_pay_later`. The ticket narrows the gate to only `active` and `installment`. I'm treating this as intentional business logic — a debtor in `partially_paid_recovery` is mid-negotiation and may not need a formal payment confirmation, while `will_pay_later` hasn't paid yet so a confirmation wouldn't apply. I will NOT reuse `isActive()` — I'll define a dedicated `PAYMENT_CONFIRMATION_STATUSES` constant and check against it explicitly to make the intent clear and prevent future drift.

**Q2 — Where is the debtor's email address?**

The ticket says "send a confirmation email to the debtor" but neither `UserPayment` nor `Sequence` has an email column in the current schema. In production this would come from a `Contact` or `Company` model. For this challenge the schema is simplified, so I'll stub the recipient with a placeholder and flag it as a required field before production use. The job and mailable architecture will be correct regardless — swapping in a real email lookup is a one-line change.

**Q3 — Should the observer also handle payment `updated` to `completed`?**

The spec says "when a UserPayment is created with status `completed`." This implies a payment is written as completed in a single atomic step. I'll implement `created` only as specified. If the real system creates payments as `pending` first and transitions them, the observer would need an `updated` hook too — worth raising before shipping.

---

## Architecture decisions

### Flow
```
UserPayment created (status=completed)
  → UserPaymentObserver::created()
    → SendPaymentConfirmation::dispatch($payment->id)  [queued]
      → load payment → load sequence
        → check gate: status in ['active', 'installment']?
          YES → PaymentConfirmationMail::send()
          NO  → Log::info('payment confirmation skipped', [reason, sequence_id, status])
```

### Why a queued job (not inline mail)?
Sending email inline in an observer blocks the HTTP request and fails silently if the mail provider is down. A queued job is retryable, observable, and follows the existing pattern in `app/Jobs/Notifications/`.

### Gate definition
I will not reuse `isActive()` — the gate for payment confirmation is a distinct business rule. I'll define:
```php
public const PAYMENT_CONFIRMATION_STATUSES = ['active', 'installment'];
```
directly on the job (or as a private constant). This makes the intent self-documenting and decoupled from the broader `ACTIVE_STATUSES` definition.

### Observer trigger condition
Only dispatch when `$payment->status === 'completed'`. A pending payment being created should not trigger a confirmation.

### Mailable
`PaymentConfirmationMail` will accept a `UserPayment` instance. It renders a simple confirmation with amount and currency. Recipient is stubbed pending the real contact lookup.

---

## Files to create / modify

| Action | File | What |
|--------|------|------|
| Create | `app/Jobs/Notifications/SendPaymentConfirmation.php` | Queued job — loads payment+sequence, checks gate, sends or logs |
| Create | `app/Mail/PaymentConfirmationMail.php` | Mailable — accepts UserPayment, renders confirmation |
| Modify | `app/Modules/Payment/Observers/UserPaymentObserver.php` | Wire `created()` to dispatch job when status=completed |
| Create | `tests/Unit/Flows/PaymentConfirmationFlowTest.php` | Contract tests (3 scenarios below) |

No migrations needed — no schema changes.

---

## Test strategy

All three tests live in `tests/Unit/Flows/PaymentConfirmationFlowTest.php` and extend `Tests\TestCase` with `RefreshDatabase` (DB records needed).

**Test 1 — Active sequence receives confirmation**
```
Mail::fake()
Create Sequence (status=active) → Create UserPayment (status=completed)
Manually call job handle()
Assert Mail::assertSent(PaymentConfirmationMail::class)
```

**Test 2 — Cancelled sequence is silently skipped**
```
Mail::fake()
Create Sequence (status=cancelled) → Create UserPayment (status=completed)
Manually call job handle()
Assert Mail::assertNotSent(PaymentConfirmationMail::class)
```

**Test 3 — Skip reason is logged**
```
Log::fake() / Log::spy()  (or use withoutExceptionHandling + asserting log channel)
Create Sequence (status=cancelled) → Create UserPayment
Manually call job handle()
Assert Log::info was called with context containing sequence_id and status
```

**Test 4 — Observer dispatches job on completed payment**
```
Queue::fake()
Create Sequence (status=active) → Create UserPayment (status=completed)
Assert Queue::assertPushed(SendPaymentConfirmation::class)
```

**Test 5 — Observer does NOT dispatch on pending payment**
```
Queue::fake()
Create Sequence → Create UserPayment (status=pending)
Assert Queue::assertNotPushed(SendPaymentConfirmation::class)
```

---

## What I am deliberately NOT doing

- Not adding an `updated` observer hook — spec says `created` only
- Not querying a real contact email — schema doesn't have one; stubbing is correct for now
- Not reusing `isActive()` — the gate is a distinct business rule
- Not adding a Blade view for the mail — `Mailable::html()` inline is sufficient for a stub; the template is outside this ticket's scope
